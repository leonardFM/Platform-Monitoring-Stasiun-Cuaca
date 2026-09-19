use anyhow::{Context, Result};

#[derive(Debug, Clone)]
pub struct Config {
    pub database_url: String,
    pub rabbitmq_url: String,
    pub rabbitmq_queue: String,
}

impl Config {
    pub fn from_env() -> Result<Self> {
        Ok(Self {
            database_url: std::env::var("DATABASE_URL").context("DATABASE_URL is not set")?,
            rabbitmq_url: std::env::var("RABBITMQ_URL").context("RABBITMQ_URL is not set")?,
            rabbitmq_queue: std::env::var("RABBITMQ_QUEUE")
                .unwrap_or_else(|_| "telemetry.ingest".to_string()),
        })
    }
}