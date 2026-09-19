use anyhow::{Context, Result};

#[derive(Debug, Clone)]
pub struct Config {
    pub database_url: String,
    pub rabbitmq_url: String,
    pub rabbitmq_queue: String,
    pub bind_host: String,
    pub bind_port: u16,
    pub max_batch_size: usize,
}

impl Config {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            database_url: std::env::var("DATABASE_URL").context("DATABASE_URL is not set")?,
            rabbitmq_url: std::env::var("RABBITMQ_URL").context("RABBITMQ_URL is not set")?,
            rabbitmq_queue: std::env::var("RABBITMQ_QUEUE")
                .unwrap_or_else(|_| "telemetry.ingest".to_string()),
            bind_host: std::env::var("BIND_HOST").unwrap_or_else(|_| "0.0.0.0".to_string()),
            bind_port: std::env::var("BIND_PORT")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(8080),
            max_batch_size: std::env::var("MAX_BATCH_SIZE")
                .ok()
                .and_then(|v| v.parse().ok())
                .unwrap_or(100),
        })
    }
}