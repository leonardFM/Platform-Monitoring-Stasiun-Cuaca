pub mod amqp;
pub mod api;
pub mod config;
pub mod state;

use crate::api::errors::ApiError;
use actix_cors::Cors;
use actix_web::{web, App, HttpServer};
use sqlx::postgres::PgPoolOptions;
use tracing::info;
use tracing_subscriber::EnvFilter;

#[actix_web::main]
async fn main() -> std::io::Result<()> {
    init_tracing();

    let config = match config::Config::from_env() {
        Ok(c) => c,
        Err(err) => {
            tracing::error!("invalid configuration: {err:#}");
            std::process::exit(1);
        }
    };

    let pool = match PgPoolOptions::new()
        .max_connections(10)
        .acquire_timeout(std::time::Duration::from_secs(10))
        .connect(&config.database_url)
        .await
    {
        Ok(p) => p,
        Err(err) => {
            tracing::error!("failed to connect to postgres: {err:#}");
            std::process::exit(1);
        }
    };
    if let Err(err) = sqlx::query("SELECT 1").execute(&pool).await {
        tracing::error!("postgres not ready: {err:#}");
        std::process::exit(1);
    }
    info!("connected to postgres");

    let amqp = match amqp::AmqpPublisher::connect(&config.rabbitmq_url, &config.rabbitmq_queue).await {
        Ok(p) => p,
        Err(err) => {
            tracing::error!("failed to connect to rabbitmq: {err:#}");
            std::process::exit(1);
        }
    };
    info!("connected to rabbitmq, queue = {}", config.rabbitmq_queue);

    let state = web::Data::new(state::AppState {
        db: pool,
        amqp,
        max_batch_size: config.max_batch_size,
    });
    let bind_addr = format!("{}:{}", config.bind_host, config.bind_port);

    let server = HttpServer::new(move || {
        let json_config = web::JsonConfig::default()
            .limit(1024 * 1024)
            .error_handler(|err, _req| -> actix_web::Error {
                let api_err = match err {
                    actix_web::error::JsonPayloadError::Overflow { limit } => {
                        ApiError::PayloadTooLarge(format!("request body exceeds {limit} bytes"))
                    }
                    actix_web::error::JsonPayloadError::ContentType => {
                        ApiError::BadRequest("Content-Type must be application/json".into())
                    }
                    actix_web::error::JsonPayloadError::Deserialize(e) => {
                        ApiError::BadRequest(format!("invalid JSON body: {e}"))
                    }
                    actix_web::error::JsonPayloadError::Payload(e) => {
                        ApiError::BadRequest(format!("invalid request body: {e}"))
                    }
                    _ => ApiError::BadRequest("invalid request body".into()),
                };
                api_err.into()
            });

        let cors = Cors::permissive();

        App::new()
            .app_data(json_config)
            .wrap(cors)
            .app_data(state.clone())
            .route("/healthz", web::get().to(api::handlers::healthz))
            .route(
                "/api/v1/ingest/telemetry",
                web::post().to(api::handlers::ingest_telemetry),
            )
            .route(
                "/api/v1/ingest/telemetry/batch",
                web::post().to(api::handlers::ingest_telemetry_batch),
            )
            .route(
                "/api/v1/dashboard",
                web::get().to(api::handlers::dashboard),
            )
    })
    .bind(&bind_addr)?
    .shutdown_timeout(30)
    .run();

    let server_handle = server.handle();
    tokio::spawn(async move {
        shutdown_signal().await;
        info!("shutdown signal received; draining http server");
        let _ = server_handle.stop(true).await;
    });

    server.await
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