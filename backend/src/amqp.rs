use anyhow::{Context, Result};
use lapin::{
    options::{BasicPublishOptions, ConfirmSelectOptions, QueueDeclareOptions},
    types::FieldTable,
    BasicProperties, Channel, Connection, ConnectionProperties,
};
use weather_shared::telemetry::IngestMessage;

#[derive(Clone)]
pub struct AmqpPublisher {
    channel: Channel,
    queue: String,
}

impl AmqpPublisher {
    pub async fn connect(url: &str, queue: &str) -> Result<Self> {
        let conn = Connection::connect(
            url,
            ConnectionProperties::default()
                .with_connection_name("weather-backend".into()),
        )
        .await
        .with_context(|| format!("cannot connect to RabbitMQ at {url}"))?;

        let channel = conn.create_channel().await.context("cannot create channel")?;
        channel
            .queue_declare(
                queue,
                QueueDeclareOptions {
                    durable: true,
                    ..Default::default()
                },
                FieldTable::default(),
            )
            .await
            .with_context(|| format!("cannot declare queue {queue}"))?;
        channel
            .confirm_select(ConfirmSelectOptions::default())
            .await
            .context("cannot enable publisher confirms")?;

        Ok(Self {
            channel,
            queue: queue.to_string(),
        })
    }

    /// Publishes a message to the telemetry queue and waits for the broker's
    /// per-message confirmation (publisher confirms).
    pub async fn publish(&self, message: &IngestMessage) -> Result<()> {
        let payload = serde_json::to_vec(message)?;
        let props = BasicProperties::default()
            .with_content_type("application/json".into())
            .with_delivery_mode(2);

        let confirm = self
            .channel
            .basic_publish(
                "",
                &self.queue,
                BasicPublishOptions::default(),
                payload.as_slice(),
                props,
            )
            .await
            .context("failed to publish message")?;

        let confirmation = confirm
            .await
            .context("failed waiting for publisher confirmation")?;
        if !confirmation.is_ack() {
            anyhow::bail!("broker rejected message (nack)");
        }
        Ok(())
    }
}