use crate::api::errors::ApiError;
use serde_json::Value;
use sqlx::types::Json;
use sqlx::{FromRow, PgPool};
use uuid::Uuid;

#[derive(Debug, Clone, FromRow)]
pub struct Device {
    pub id: Uuid,
    pub name: String,
    pub calibration: Json<Value>,
}

/// Authenticates an API key against the devices table.
pub async fn authenticate(pool: &PgPool, api_key: &str) -> Result<Device, ApiError> {
    let device = sqlx::query_as::<_, Device>(
        "SELECT id, name, calibration FROM devices WHERE api_key = $1 AND is_active = TRUE",
    )
    .bind(api_key)
    .fetch_optional(pool)
    .await
    .map_err(|e| {
        tracing::error!(?e, "device lookup failed");
        ApiError::Internal(e.to_string())
    })?;

    device.ok_or_else(|| ApiError::Unauthorized("invalid or inactive API key".to_string()))
}