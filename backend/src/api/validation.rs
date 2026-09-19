use crate::api::errors::ApiError;
use chrono::{DateTime, Utc};
use weather_shared::telemetry::TelemetryReading;

/// Wire-level (schema) validation performed by the HTTP API before anything
/// reaches the queue. Rejects structurally-impossible payloads; the worker
/// applies strict sensor/range validation and quality flagging downstream.
pub fn validate_reading(reading: &TelemetryReading, now: DateTime<Utc>) -> Result<(), ApiError> {
    let s = &reading.sensors;

    let checks: [(&str, f32, f32, f32); 5] = [
        ("temperature_c", s.temperature_c, -100.0, 100.0),
        ("humidity_pct", s.humidity_pct, 0.0, 100.0),
        ("pressure_hpa", s.pressure_hpa, 300.0, 1200.0),
        ("windspeed_ms", s.windspeed_ms, 0.0, 200.0),
        ("wind_direction_deg", s.wind_direction_deg, 0.0, 360.0),
    ];

    let mut problems = Vec::new();
    for (name, value, lo, hi) in checks {
        if !value.is_finite() || value < lo || value > hi {
            problems.push(format!("{name} out of bounds [{lo}, {hi}]"));
        }
    }
    if s.rain_counter < 0 {
        problems.push("rain_counter cannot be negative".to_string());
    }

    let age_secs = (now - reading.taken_at).num_seconds();
    if age_secs < -300 {
        problems.push("taken_at is too far in the future".to_string());
    }
    if age_secs > 7 * 24 * 3600 {
        problems.push("taken_at is older than 7 days".to_string());
    }

    if problems.is_empty() {
        Ok(())
    } else {
        Err(ApiError::BadRequest(problems.join("; ")))
    }
}

/// Convenience for batch validation timestamps.
pub fn now() -> DateTime<Utc> {
    Utc::now()
}