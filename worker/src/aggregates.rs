use anyhow::{Context, Result};
use chrono::{DateTime, Utc};
use sqlx::{Postgres, Transaction};
use uuid::Uuid;

use crate::processing::Calibrated;

/// Incremental hourly/dayly rollup upsert performed after every accepted
/// reading. Idempotent: replays simply recompute the same windows.
pub async fn upsert(
    tx: &mut Transaction<'_, Postgres>,
    device_id: Uuid,
    taken_at: DateTime<Utc>,
    c: &Calibrated,
    rain_delta_mm: f64,
) -> Result<()> {
    for granularity in ["hour", "day"] {
        upsert_one(tx, device_id, granularity, taken_at, c, rain_delta_mm).await?;
    }
    Ok(())
}

async fn upsert_one(
    tx: &mut Transaction<'_, Postgres>,
    device_id: Uuid,
    granularity: &str,
    taken_at: DateTime<Utc>,
    c: &Calibrated,
    rain_delta_mm: f64,
) -> Result<()> {
    sqlx::query(
        r#"
        INSERT INTO station_aggregates (
            device_id, granularity, period_start, count,
            temperature_avg, temperature_min, temperature_max,
            humidity_avg, humidity_min, humidity_max,
            pressure_avg, pressure_min, pressure_max,
            windspeed_avg, windspeed_min, windspeed_max,
            wind_direction_avg, rain_total_mm
        )
        VALUES (
            $1, $2, date_trunc($2::text, $3), 1,
            $4, $4, $4,
            $5, $5, $5,
            $6, $6, $6,
            $7, $7, $7,
            $8, $9
        )
        ON CONFLICT (device_id, granularity, period_start) DO UPDATE SET
            count = station_aggregates.count + 1,
            temperature_avg = (station_aggregates.temperature_avg * station_aggregates.count + excluded.temperature_avg) / (station_aggregates.count + 1),
            temperature_min = LEAST(station_aggregates.temperature_min, excluded.temperature_min),
            temperature_max = GREATEST(station_aggregates.temperature_max, excluded.temperature_max),
            humidity_avg = (station_aggregates.humidity_avg * station_aggregates.count + excluded.humidity_avg) / (station_aggregates.count + 1),
            humidity_min = LEAST(station_aggregates.humidity_min, excluded.humidity_min),
            humidity_max = GREATEST(station_aggregates.humidity_max, excluded.humidity_max),
            pressure_avg = (station_aggregates.pressure_avg * station_aggregates.count + excluded.pressure_avg) / (station_aggregates.count + 1),
            pressure_min = LEAST(station_aggregates.pressure_min, excluded.pressure_min),
            pressure_max = GREATEST(station_aggregates.pressure_max, excluded.pressure_max),
            windspeed_avg = (station_aggregates.windspeed_avg * station_aggregates.count + excluded.windspeed_avg) / (station_aggregates.count + 1),
            windspeed_min = LEAST(station_aggregates.windspeed_min, excluded.windspeed_min),
            windspeed_max = GREATEST(station_aggregates.windspeed_max, excluded.windspeed_max),
            wind_direction_avg = (station_aggregates.wind_direction_avg * station_aggregates.count + excluded.wind_direction_avg) / (station_aggregates.count + 1),
            rain_total_mm = station_aggregates.rain_total_mm + excluded.rain_total_mm
        "#,
    )
    .bind(device_id)
    .bind(granularity)
    .bind(taken_at)
    .bind(c.temperature_c)
    .bind(c.humidity_pct)
    .bind(c.pressure_hpa)
    .bind(c.windspeed_ms)
    .bind(c.wind_direction_deg)
    .bind(rain_delta_mm)
    .execute(&mut **tx)
    .await
    .with_context(|| format!("upsert {granularity} aggregate"))?;

    Ok(())
}

/// Recomputation pass: rebuilds the most recent hour/day aggregates directly
/// from the base `telemetry_readings`, self-healing any incremental drift.
pub async fn recalculate(pool: &sqlx::PgPool) -> Result<()> {
    recalc_granularity(pool, "hour", "48 hours").await?;
    recalc_granularity(pool, "day", "2 days").await?;
    Ok(())
}

async fn recalc_granularity(pool: &sqlx::PgPool, granularity: &str, window: &str) -> Result<()> {
    sqlx::query(
        r#"
        INSERT INTO station_aggregates (
            device_id, granularity, period_start, count,
            temperature_avg, temperature_min, temperature_max,
            humidity_avg, humidity_min, humidity_max,
            pressure_avg, pressure_min, pressure_max,
            windspeed_avg, windspeed_min, windspeed_max,
            wind_direction_avg, rain_total_mm
        )
        SELECT
            s.device_id,
            $1,
            date_trunc($1::text, s.taken_at),
            COUNT(*)::BIGINT,
            AVG(s.temperature_c), MIN(s.temperature_c), MAX(s.temperature_c),
            AVG(s.humidity_pct), MIN(s.humidity_pct), MAX(s.humidity_pct),
            AVG(s.pressure_hpa), MIN(s.pressure_hpa), MAX(s.pressure_hpa),
            AVG(s.windspeed_ms), MIN(s.windspeed_ms), MAX(s.windspeed_ms),
            AVG(s.wind_direction_deg),
            SUM(s.rain_delta_mm)
        FROM telemetry_readings s
        WHERE s.taken_at >= now() - ($2::text)::interval
        GROUP BY s.device_id, date_trunc($1::text, s.taken_at)
        ON CONFLICT (device_id, granularity, period_start) DO UPDATE SET
            count = excluded.count,
            temperature_avg = excluded.temperature_avg,
            temperature_min = excluded.temperature_min,
            temperature_max = excluded.temperature_max,
            humidity_avg = excluded.humidity_avg,
            humidity_min = excluded.humidity_min,
            humidity_max = excluded.humidity_max,
            pressure_avg = excluded.pressure_avg,
            pressure_min = excluded.pressure_min,
            pressure_max = excluded.pressure_max,
            windspeed_avg = excluded.windspeed_avg,
            windspeed_min = excluded.windspeed_min,
            windspeed_max = excluded.windspeed_max,
            wind_direction_avg = excluded.wind_direction_avg,
            rain_total_mm = excluded.rain_total_mm
        "#,
    )
    .bind(granularity)
    .bind(window)
    .execute(pool)
    .await
    .with_context(|| format!("recalculate {granularity} aggregates"))?;

    Ok(())
}