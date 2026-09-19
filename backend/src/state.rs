use crate::amqp::AmqpPublisher;
use sqlx::PgPool;

#[derive(Clone)]
pub struct AppState {
    pub db: PgPool,
    pub amqp: AmqpPublisher,
    pub max_batch_size: usize,
}