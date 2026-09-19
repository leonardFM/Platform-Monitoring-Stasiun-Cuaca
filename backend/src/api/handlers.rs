use crate::api::auth::authenticate;
use crate::api::errors::ApiError;
use crate::api::validation;
use crate::state::AppState;
use actix_web::{web, HttpRequest, HttpResponse};
use chrono::{DateTime, Utc};
use serde_json::{json, Value};
use sqlx::types::Json;
use sqlx::{FromRow, PgPool};
use uuid::Uuid;
use weather_shared::telemetry::{BatchRequest, IngestMessage, TelemetryReading};

pub async fn healthz(state: web::Data<AppState>) -> HttpResponse {
    match sqlx::query("SELECT 1").execute(&state.db).await {
        Ok(_) => HttpResponse::Ok().json(json!({"status": "ok"})),
        Err(e) => {
            tracing::warn!(?e, "health check db probe failed");
            HttpResponse::ServiceUnavailable().json(json!({"status": "degraded"}))
        }
    }
}

pub async fn ingest_telemetry(
    req: HttpRequest,
    state: web::Data<AppState>,
    body: web::Json<TelemetryReading>,
) -> Result<HttpResponse, ApiError> {
    let reading = body.into_inner();
    let _ = ingest(&req, &state, &reading).await?;
    Ok(HttpResponse::Accepted()
        .json(json!({"accepted": true, "message_id": reading.message_id})))
}

pub async fn ingest_telemetry_batch(
    req: HttpRequest,
    state: web::Data<AppState>,
    body: web::Json<BatchRequest>,
) -> Result<HttpResponse, ApiError> {
    let readings = body.into_inner().readings;
    if readings.is_empty() {
        return Err(ApiError::BadRequest("batch must contain at least one reading".into()));
    }
    if readings.len() > state.max_batch_size {
        return Err(ApiError::PayloadTooLarge(format!(
            "batch size {} exceeds limit {}",
            readings.len(),
            state.max_batch_size
        )));
    }

    for reading in &readings {
        ingest(&req, &state, reading).await?;
    }

    Ok(HttpResponse::Accepted()
        .json(json!({"accepted": readings.len(), "message_ids": readings.iter().map(|r| r.message_id).collect::<Vec<_>>()})))
}

async fn ingest(req: &HttpRequest, state: &AppState, reading: &TelemetryReading) -> Result<(), ApiError> {
    validation::validate_reading(reading, Utc::now())?;
    let api_key = api_key_from(req)?;
    let device = authenticate(&state.db, api_key).await?;

    let message = IngestMessage::from_reading(device.id, reading);
    state.amqp.publish(&message).await.map_err(|e| {
        tracing::error!(?e, message_id = %message.message_id, "failed to publish telemetry");
        ApiError::Broker(format!("telemetry could not be accepted: {e}"))
    })?;
    tracing::info!(device_id = %device.id, message_id = %message.message_id, "telemetry accepted");
    Ok(())
}

fn api_key_from(req: &HttpRequest) -> Result<&str, ApiError> {
    req.headers()
        .get("x-api-key")
        .and_then(|v| v.to_str().ok())
        .filter(|v| !v.is_empty())
        .ok_or_else(|| ApiError::Unauthorized("missing or invalid x-api-key header".into()))
}

// ---------------------------------------------------------------------------
// Dashboard (single-page frontend)
// ---------------------------------------------------------------------------

#[derive(Debug, FromRow)]
struct LatestRow {
    id: Uuid,
    name: String,
    location: Json<Value>,
    taken_at: Option<DateTime<Utc>>,
    temperature_c: Option<f32>,
    humidity_pct: Option<f32>,
    pressure_hpa: Option<f32>,
    windspeed_ms: Option<f32>,
    wind_direction_deg: Option<f32>,
    rain_counter: Option<i64>,
    rain_delta_mm: Option<f64>,
    quality_score: Option<i16>,
}

#[derive(Debug, FromRow)]
struct RainTodayRow {
    id: Uuid,
    rain_mm: f64,
}

#[derive(Debug, FromRow)]
struct SeriesRow {
    period_start: DateTime<Utc>,
    device_name: String,
    temperature_avg: Option<f32>,
    humidity_avg: Option<f32>,
    pressure_avg: Option<f32>,
    windspeed_avg: Option<f32>,
    wind_direction_avg: Option<f32>,
    rain_total_mm: f64,
}

#[derive(Debug, FromRow)]
struct RecentRow {
    taken_at: DateTime<Utc>,
    device_name: String,
    temperature_c: Option<f32>,
    humidity_pct: Option<f32>,
    pressure_hpa: Option<f32>,
    windspeed_ms: Option<f32>,
    wind_direction_deg: Option<f32>,
    rain_counter: Option<i64>,
    rain_delta_mm: Option<f64>,
    quality_score: Option<i16>,
    quality_flags: Json<Value>,
}

pub async fn dashboard(state: web::Data<AppState>) -> Result<HttpResponse, ApiError> {
    let devices = query_devices(&state.db).await?;
    let rain = query_rain_today(&state.db).await?;
    let series = query_series(&state.db).await?;
    let recent = query_recent(&state.db).await?;

    let rain_by_device: std::collections::HashMap<Uuid, f64> = rain
        .into_iter()
        .map(|r| (r.id, r.rain_mm))
        .collect();

    let devices_json: Vec<Value> = devices
        .iter()
        .map(|d| {
            json!({
                "id": d.id,
                "name": d.name,
                "location": d.location.0,
                "rain_today_mm": rain_by_device.get(&d.id).copied().unwrap_or(0.0),
                "latest": match d.taken_at {
                    Some(_) => json!({
                        "taken_at": d.taken_at,
                        "temperature_c": d.temperature_c,
                        "humidity_pct": d.humidity_pct,
                        "pressure_hpa": d.pressure_hpa,
                        "windspeed_ms": d.windspeed_ms,
                        "wind_direction_deg": d.wind_direction_deg,
                        "rain_counter": d.rain_counter,
                        "rain_delta_mm": d.rain_delta_mm,
                        "quality_score": d.quality_score,
                    }),
                    None => Value::Null,
                },
            })
        })
        .collect();

    let series_json: Vec<Value> = series
        .iter()
        .map(|s| {
            json!({
                "period_start": s.period_start,
                "device_name": s.device_name,
                "temperature_avg": s.temperature_avg,
                "humidity_avg": s.humidity_avg,
                "pressure_avg": s.pressure_avg,
                "windspeed_avg": s.windspeed_avg,
                "wind_direction_avg": s.wind_direction_avg,
                "rain_total_mm": s.rain_total_mm,
            })
        })
        .collect();

    let recent_json: Vec<Value> = recent
        .iter()
        .map(|r| {
            json!({
                "taken_at": r.taken_at,
                "device_name": r.device_name,
                "temperature_c": r.temperature_c,
                "humidity_pct": r.humidity_pct,
                "pressure_hpa": r.pressure_hpa,
                "windspeed_ms": r.windspeed_ms,
                "wind_direction_deg": r.wind_direction_deg,
                "rain_counter": r.rain_counter,
                "rain_delta_mm": r.rain_delta_mm,
                "quality_score": r.quality_score,
                "quality_flags": r.quality_flags.0,
            })
        })
        .collect();

    Ok(HttpResponse::Ok().json(json!({
        "devices": devices_json,
        "series": series_json,
        "recent": recent_json,
    })))
}

async fn query_devices(pool: &PgPool) -> Result<Vec<LatestRow>, ApiError> {
    sqlx::query_as::<_, LatestRow>(
        r#"
        SELECT DISTINCT ON (d.id)
               d.id, d.name, d.location,
               r.taken_at, r.temperature_c, r.humidity_pct, r.pressure_hpa,
               r.windspeed_ms, r.wind_direction_deg, r.rain_counter,
               r.rain_delta_mm, r.quality_score
        FROM devices d
        LEFT JOIN telemetry_readings r ON r.device_id = d.id
        ORDER BY d.id, r.taken_at DESC NULLS LAST
        "#,
    )
    .fetch_all(pool)
    .await
    .map_err(|e| {
        tracing::error!(?e, "dashboard devices query failed");
        ApiError::Internal(e.to_string())
    })
}

async fn query_rain_today(pool: &PgPool) -> Result<Vec<RainTodayRow>, ApiError> {
    sqlx::query_as::<_, RainTodayRow>(
        r#"
        SELECT d.id, COALESCE(SUM(r.rain_delta_mm), 0)::DOUBLE PRECISION AS rain_mm
        FROM devices d
        LEFT JOIN telemetry_readings r
               ON r.device_id = d.id AND r.taken_at >= date_trunc('day', now())
        GROUP BY d.id
        "#,
    )
    .fetch_all(pool)
    .await
    .map_err(|e| {
        tracing::error!(?e, "dashboard rain query failed");
        ApiError::Internal(e.to_string())
    })
}

async fn query_series(pool: &PgPool) -> Result<Vec<SeriesRow>, ApiError> {
    sqlx::query_as::<_, SeriesRow>(
        r#"
        SELECT a.period_start, d.name AS device_name,
               a.temperature_avg, a.humidity_avg, a.pressure_avg,
               a.windspeed_avg, a.wind_direction_avg, a.rain_total_mm
        FROM station_aggregates a
        JOIN devices d ON d.id = a.device_id
        WHERE a.granularity = 'hour'
          AND a.period_start >= now() - interval '24 hours'
        ORDER BY a.period_start ASC, d.name ASC
        "#,
    )
    .fetch_all(pool)
    .await
    .map_err(|e| {
        tracing::error!(?e, "dashboard series query failed");
        ApiError::Internal(e.to_string())
    })
}

async fn query_recent(pool: &PgPool) -> Result<Vec<RecentRow>, ApiError> {
    sqlx::query_as::<_, RecentRow>(
        r#"
        SELECT r.taken_at, d.name AS device_name,
               r.temperature_c, r.humidity_pct, r.pressure_hpa,
               r.windspeed_ms, r.wind_direction_deg, r.rain_counter,
               r.rain_delta_mm, r.quality_score, r.quality_flags
        FROM telemetry_readings r
        JOIN devices d ON d.id = r.device_id
        ORDER BY r.taken_at DESC
        LIMIT 30
        "#,
    )
    .fetch_all(pool)
    .await
    .map_err(|e| {
        tracing::error!(?e, "dashboard recent query failed");
        ApiError::Internal(e.to_string())
    })
}