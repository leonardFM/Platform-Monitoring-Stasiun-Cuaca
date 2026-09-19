use serde::{Deserialize, Serialize};
use std::collections::HashMap;

/// Coefficient pair applied to a raw reading: `value = raw * gain + offset`.
#[derive(Debug, Clone, Copy, Serialize, Deserialize)]
#[serde(deny_unknown_fields)]
pub struct Coeffs {
    #[serde(default)]
    pub gain: Option<f32>,
    #[serde(default)]
    pub offset: Option<f32>,
}

/// Device calibration map, keyed by sensor name. Missing/absent entries are identity.
#[derive(Debug, Clone, Default)]
pub struct CalibrationMap {
    inner: HashMap<String, Coeffs>,
}

impl CalibrationMap {
    pub fn from_json_value(value: serde_json::Value) -> Self {
        let inner = serde_json::from_value::<HashMap<String, Coeffs>>(value).unwrap_or_default();
        Self { inner }
    }

    pub fn has_entries(&self) -> bool {
        !self.inner.is_empty()
    }

    fn gain(&self, sensor: &str) -> f32 {
        self.inner
            .get(sensor)
            .and_then(|c| c.gain)
            .unwrap_or(1.0)
    }

    fn offset(&self, sensor: &str) -> f32 {
        self.inner.get(sensor).and_then(|c| c.offset).unwrap_or(0.0)
    }

    /// `value = raw * gain + offset`.
    pub fn calibrate(&self, sensor: &str, raw: f32) -> f32 {
        raw * self.gain(sensor) + self.offset(sensor)
    }
}