use anyhow::{Context, Result};
use chrono::{DateTime, Utc};
use serde_json::Value;
use sqlx::types::Json;
use sqlx::{FromRow, Postgres, Transaction};
use uuid::Uuid;
use weather_shared::calibration::CalibrationMap;
use weather_shared::telemetry::{range_errors, IngestMessage, SensorReadings};

use crate::aggregates;

#[derive(Debug, Clone, Copy, PartialEq, Eq)]
pub enum Outcome {
    /// Successfully persisted (or handled as a duplicate awaiting ack).
    Ack,
    /// Deliberately rejected; optionally requeued.
    Reject { requeue: bool },
}

/// Calibrated copy of a reading (raw values after gain/offset application).
#[derive(Debug, Clone, Copy)]
pub struct Calibrated {
    pub temperature_c: f32,
    pub humidity_pct: f32,
    pub pressure_hpa: f32,
    pub windspeed_ms: f32,
    pub wind_direction_deg: f32,
    pub rain_counter: i64,
}

pub async fn process(pool: &sqlx::PgPool, payload: &[u8]) -> Result<Outcome> {
    let message: IngestMessage = serde_json::from_slice(payload).context("decode message")?;
    dispatch(pool, &message).await
}

async fn dispatch(pool: &sqlx::PgPool, message: &IngestMessage) -> Result<Outcome> {
    // 1. Sensor validation: the device must exist and be active.
    let device = load_device(pool, message.device_id)
        .await?
        .filter(|d| d.is_active)
        .with_context(|| format!("device {} not found or inactive", message.device_id))?;

    // 2. Range validation -> quality flags + score (strict physical bounds).
    let mut quality_flags = range_errors(&message.sensors);
    let mut quality_score = quality_score_for(&quality_flags);

    // 3. Calibration: value = raw * gain + offset (per device config).
    let calibration = CalibrationMap::from_json_value(device.calibration.0);
    let calibrated = apply_calibration(&message.sensors, &calibration);
    let has_calibration = has_calibration(&calibration);
    if has_calibration {
        quality_flags.push("calibrated".to_string());
    }

    let mut tx = pool
        .begin()
        .await
        .context("begin transaction")?;

    // 4. Rain counter processing is serialized per device so concurrent
    //    inserts compute deltas against a consistent "previous" reading.
    tx_locking_advisory_key(&mut tx, message.device_id).await?;
    let previous = fetch_previous(&mut tx, message.device_id).await?;

    // 5. Idempotency: UNIQUE (device_id, message_id).
    let (rain_delta, rain_flags) =
        rain::delta(&previous, message.taken_at, message.sensors.rain_counter);
    if rain_flags.iter().any(|f| f.starts_with("rain_initial")) {
        quality_score = (quality_score - 5).max(0);
    }
    if has_rain_reset(&rain_flags) {
        quality_score = (quality_score - 5).max(0);
    }
    quality_flags.extend(rain_flags);

    let inserted = insert_reading(
        &mut tx,
        message,
        &calibrated,
        rain_delta,
        &quality_flags,
        quality_score,
    )
    .await?;

    if !inserted {
        // Duplicate message: nothing to do, ack.
        tx.rollback().await.context("rollback duplicate")?;
        tracing::info!(
            device_id = %message.device_id,
            message_id = %message.message_id,
            "duplicate message_id, skipping"
        );
        return Ok(Outcome::Ack);
    }

    // 6. Aggregate update (hour/day rollups).
    aggregates::upsert(&mut tx, message.device_id, message.taken_at, &calibrated, rain_delta)
        .await
        .context("aggregate upsert")?;

    tx.commit().await.context("commit transaction")?;

    tracing::info!(
        device_id = %message.device_id,
        message_id = %message.message_id,
        quality_score,
        "reading persisted"
    );
    Ok(Outcome::Ack)
}

#[derive(Debug, FromRow)]
struct DeviceRow {
    is_active: bool,
    calibration: Json<Value>,
}

async fn load_device(pool: &sqlx::PgPool, device_id: Uuid) -> Result<Option<DeviceRow>> {
    Ok(sqlx::query_as::<_, DeviceRow>(
        "SELECT is_active, calibration FROM devices WHERE id = $1",
    )
    .bind(device_id)
    .fetch_optional(pool)
    .await?)
}

#[derive(Debug, FromRow)]
pub struct PreviousReading {
    pub rain_counter: Option<i64>,
    pub taken_at: Option<DateTime<Utc>>,
}

async fn tx_locking_advisory_key(tx: &mut Transaction<'_, Postgres>, device_id: Uuid) -> Result<()> {
    sqlx::query("SELECT pg_advisory_xact_lock(hashtextextended($1::text, 0))")
        .bind(device_id)
        .execute(&mut **tx)
        .await
        .context("acquire per-device advisory lock")
        .map(|_| ())
}

async fn fetch_previous(
    tx: &mut Transaction<'_, Postgres>,
    device_id: Uuid,
) -> Result<Option<PreviousReading>> {
    Ok(sqlx::query_as::<_, PreviousReading>(
        "SELECT rain_counter, taken_at FROM telemetry_readings \
         WHERE device_id = $1 ORDER BY taken_at DESC LIMIT 1",
    )
    .bind(device_id)
    .fetch_optional(&mut **tx)
    .await?)
}

async fn insert_reading(
    tx: &mut Transaction<'_, Postgres>,
    message: &IngestMessage,
    calibrated: &Calibrated,
    rain_delta_mm: f64,
    quality_flags: &[String],
    quality_score: i16,
) -> Result<bool> {
    let result = sqlx::query(
        r#"
        INSERT INTO telemetry_readings (
            device_id, message_id, taken_at,
            temperature_c, humidity_pct, pressure_hpa,
            windspeed_ms, wind_direction_deg, rain_counter,
            rain_delta_mm, quality_flags, quality_score
        )
        VALUES ($1, $2, $3, $4, $5, $6, $7, $8, $9, $10, $11, $12)
        ON CONFLICT (device_id, message_id) DO NOTHING
        "#,
    )
    .bind(message.device_id)
    .bind(message.message_id)
    .bind(message.taken_at)
    .bind(calibrated.temperature_c)
    .bind(calibrated.humidity_pct)
    .bind(calibrated.pressure_hpa)
    .bind(calibrated.windspeed_ms)
    .bind(calibrated.wind_direction_deg)
    .bind(calibrated.rain_counter)
    .bind(rain_delta_mm)
    .bind(Json(quality_flags.to_vec()))
    .bind(quality_score)
    .execute(&mut **tx)
    .await
    .context("insert reading")?;

    Ok(result.rows_affected() == 1)
}

fn apply_calibration(sensors: &SensorReadings, calibration: &CalibrationMap) -> Calibrated {
    Calibrated {
        temperature_c: calibration.calibrate("temperature_c", sensors.temperature_c),
        humidity_pct: calibration.calibrate("humidity_pct", sensors.humidity_pct),
        pressure_hpa: calibration.calibrate("pressure_hpa", sensors.pressure_hpa),
        windspeed_ms: calibration.calibrate("windspeed_ms", sensors.windspeed_ms),
        wind_direction_deg: calibration.calibrate("wind_direction_deg", sensors.wind_direction_deg),
        rain_counter: sensors.rain_counter,
    }
}

fn has_calibration(calibration: &CalibrationMap) -> bool {
    calibration.has_entries()
}

fn quality_score_for(flags: &[String]) -> i16 {
    // Start from 100 and scale down by the fraction of out-of-range channels.
    let out_of_range = flags
        .iter()
        .filter(|f| f.starts_with("out_of_range"))
        .count();
    let total = total_channels() as i16;
    let valid = total - out_of_range as i16;
    (100 * valid / total).max(0)
}

fn total_channels() -> usize {
    // temperature, humidity, pressure, windspeed, wind_direction, rain_counter
    6
}

fn has_rain_reset(flags: &[String]) -> bool {
    flags.iter().any(|f| f == "rain_reset")
}

mod rain {
    use super::PreviousReading;
    use chrono::{DateTime, Utc};

    /// Computes a rain delta in counter units (interpreted as millimetres).
    /// Handles first readings, counter resets and out-of-order arrivals.
    pub fn delta(
        previous: &Option<PreviousReading>,
        taken_at: DateTime<Utc>,
        current: i64,
    ) -> (f64, Vec<String>) {
        let mut flags = Vec::new();
        let delta = match previous {
            None => {
                flags.push("rain_initial".to_string());
                0.0
            }
            Some(prev) => match (prev.taken_at, prev.rain_counter) {
                (None, _) => {
                    flags.push("rain_initial".to_string());
                    0.0
                }
                (Some(_prev_ts), None) => {
                    flags.push("rain_initial".to_string());
                    0.0
                }
                (Some(prev_ts), Some(prev_counter)) => {
                    if taken_at <= prev_ts {
                        flags.push("rain_out_of_order".to_string());
                    }
                    if current < prev_counter {
                        flags.push("rain_reset".to_string());
                        0.0
                    } else {
                        (current - prev_counter) as f64
                    }
                }
            },
        };
        (delta, flags)
    }
}