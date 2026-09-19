use chrono::{DateTime, Utc};
use serde::{Deserialize, Serialize};
use uuid::Uuid;

/// Raw sensor telemetry published by a weather device.
/// All six channels are required; presence is a schema-level contract.
#[derive(Debug, Clone, Copy, Serialize, Deserialize, PartialEq)]
#[serde(deny_unknown_fields)]
pub struct SensorReadings {
    pub temperature_c: f32,
    pub humidity_pct: f32,
    pub pressure_hpa: f32,
    pub windspeed_ms: f32,
    pub wind_direction_deg: f32,
    pub rain_counter: i64,
}

/// Strict physical bounds per sensor (used by the worker to compute quality flags).
pub fn sensor_bounds(name: &str) -> Option<(f32, f32)> {
    Some(match name {
        "temperature_c" => (-60.0, 60.0),
        "humidity_pct" => (0.0, 100.0),
        "pressure_hpa" => (800.0, 1100.0),
        "windspeed_ms" => (0.0, 100.0),
        "wind_direction_deg" => (0.0, 360.0),
        _ => return None,
    })
}

/// Returns `out_of_range:<sensor>` flags for every channel outside its bounds.
pub fn range_errors(s: &SensorReadings) -> Vec<String> {
    let entries: [(&str, f32); 5] = [
        ("temperature_c", s.temperature_c),
        ("humidity_pct", s.humidity_pct),
        ("pressure_hpa", s.pressure_hpa),
        ("windspeed_ms", s.windspeed_ms),
        ("wind_direction_deg", s.wind_direction_deg),
    ];

    let mut flags = Vec::new();
    for (name, value) in entries {
        if let Some((lo, hi)) = sensor_bounds(name) {
            if !value.is_finite() || value < lo || value > hi {
                flags.push(format!("out_of_range:{name}"));
            }
        }
    }
    if s.rain_counter < 0 {
        flags.push("out_of_range:rain_counter".to_string());
    }
    flags
}

/// A single telemetry reading as POSTed by a device.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct TelemetryReading {
    /// Client generated idempotency key; deduplicated by (device_id, message_id).
    pub message_id: Uuid,
    pub taken_at: DateTime<Utc>,
    pub sensors: SensorReadings,
}

#[derive(Debug, Clone, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct BatchRequest {
    pub readings: Vec<TelemetryReading>,
}

/// Payload carried over the RabbitMQ telemetry queue.
#[derive(Debug, Clone, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct IngestMessage {
    pub device_id: Uuid,
    pub message_id: Uuid,
    pub taken_at: DateTime<Utc>,
    pub sensors: SensorReadings,
}

impl IngestMessage {
    pub fn from_reading(device_id: Uuid, reading: &TelemetryReading) -> Self {
        Self {
            device_id,
            message_id: reading.message_id,
            taken_at: reading.taken_at,
            sensors: reading.sensors,
        }
    }
}