pub mod aggregates;
pub mod config;
pub mod processing;

use crate::processing::Outcome;
use anyhow::{Context, Result};
use futures_util::StreamExt;
use lapin::options::{BasicQosOptions, QueueDeclareOptions};
use lapin::types::FieldTable;
use lapin::{Connection, ConnectionProperties};
use sqlx::postgres::PgPoolOptions;
use std::time::Duration;
use tracing::info;
use tracing_subscriber::EnvFilter;

const CONSUMER_TAG: &str = "weather-worker";

#[tokio::main]
async fn main() -> Result<()> {
    init_tracing();

    let config = config::Config::from_env()?;
    let pool = PgPoolOptions::new()
        .max_connections(10)
        .acquire_timeout(Duration::from_secs(10))
        .connect(&config.database_url)
        .await
        .context("connect to postgres")?;
    info!("connected to postgres");

    let conn = Connection::connect(
        &config.rabbitmq_url,
        ConnectionProperties::default().with_connection_name("weather-worker".into()),
    )
    .await
    .with_context(|| format!("connect to rabbitmq at {}", config.rabbitmq_url))?;
    let channel = conn.create_channel().await.context("create rabbitmq channel")?;
    channel
        .queue_declare(
            &config.rabbitmq_queue,
            QueueDeclareOptions {
                durable: true,
                ..Default::default()
            },
            FieldTable::default(),
        )
        .await
        .with_context(|| format!("declare queue {}", config.rabbitmq_queue))?;
    channel
        .basic_qos(1, BasicQosOptions::default())
        .await
        .context("set prefetch")?;
    let mut consumer = channel
        .basic_consume(
            &config.rabbitmq_queue,
            CONSUMER_TAG,
            lapin::options::BasicConsumeOptions::default(),
            FieldTable::default(),
        )
        .await
        .with_context(|| format!("consume queue {}", config.rabbitmq_queue))?;
    info!(
        queue = %config.rabbitmq_queue,
        "worker consuming; waiting for telemetry messages"
    );

    let recalc_pool = pool.clone();
    let recalc_handle = tokio::spawn(async move {
        let mut ticker = tokio::time::interval(Duration::from_secs(300));
        ticker.tick().await; // consume the immediate first tick
        loop {
            ticker.tick().await;
            info!("recalculating station aggregates from base readings");
            if let Err(e) = crate::aggregates::recalculate(&recalc_pool).await {
                tracing::warn!(?e, "aggregate recalculation failed");
            }
        }
    });

    let shutdown = shutdown_signal();
    tokio::pin!(shutdown);

    loop {
        tokio::select! {
            biased;
            _ = &mut shutdown => {
                info!("shutdown signal received; draining consumer");
                break;
            }
            Some(delivery) = consumer.next() => {
                let delivery = match delivery {
                    Ok(d) => d,
                    Err(e) => {
                        tracing::error!(?e, "consumer error");
                        continue;
                    }
                };

                match processing::process(&pool, delivery.data.as_slice()).await {
                    Ok(Outcome::Ack) => {
                        if let Err(e) = delivery.ack(lapin::options::BasicAckOptions::default()).await {
                            tracing::error!(?e, "failed to ack delivery");
                        }
                    }
                    Ok(Outcome::Reject { requeue }) => {
                        tracing::warn!(requeue, "message rejected");
                        if let Err(e) = delivery
                            .nack(lapin::options::BasicNackOptions { requeue, ..Default::default() })
                            .await
                        {
                            tracing::error!(?e, "failed to nack delivery");
                        }
                    }
                    Err(e) => {
                        tracing::error!(?e, "processing failed");
                        let requeue = !delivery.redelivered;
                        if let Err(e) = delivery
                            .nack(lapin::options::BasicNackOptions { requeue, ..Default::default() })
                            .await
                        {
                            tracing::error!(?e, "failed to nack delivery after processing error");
                        }
                    }
                }
            }
        }
    }

    // Drop consumer channel/connection; every processed delivery was already
    // acknowledged, so RabbitMQ resends nothing that was lost.
    recalc_handle.abort();
    drop(consumer);
    info!("worker stopped cleanly");
    Ok(())
}

fn init_tracing() {
    let filter = EnvFilter::try_from_default_env().unwrap_or_else(|_| EnvFilter::new("info"));
    tracing_subscriber::fmt().with_env_filter(filter).init();
}

async fn shutdown_signal() {
    let ctrl_c = tokio::signal::ctrl_c();
    #[cfg(unix)]
    let terminate = async {
        tokio::signal::unix::signal(tokio::signal::unix::SignalKind::terminate())
            .expect("failed to install SIGTERM handler")
            .recv()
            .await;
    };
    #[cfg(not(unix))]
    let terminate = std::future::pending::<()>();

    tokio::select! {
        _ = ctrl_c => {},
        _ = terminate => {},
    }
}