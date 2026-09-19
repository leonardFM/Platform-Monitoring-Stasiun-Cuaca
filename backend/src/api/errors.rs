use actix_web::body::BoxBody;
use actix_web::http::StatusCode;
use actix_web::{HttpResponse, ResponseError};
use serde_json::json;

#[derive(Debug, thiserror::Error)]
pub enum ApiError {
    #[error("{0}")]
    BadRequest(String),
    #[error("{0}")]
    Unauthorized(String),
    #[error("{0}")]
    PayloadTooLarge(String),
    #[error("{0}")]
    NotFound(String),
    #[error("internal server error")]
    Internal(String),
    #[error("message broker unavailable")]
    Broker(String),
}

impl ResponseError for ApiError {
    fn status_code(&self) -> StatusCode {
        match self {
            ApiError::BadRequest(_) => StatusCode::BAD_REQUEST,
            ApiError::Unauthorized(_) => StatusCode::UNAUTHORIZED,
            ApiError::PayloadTooLarge(_) => StatusCode::PAYLOAD_TOO_LARGE,
            ApiError::NotFound(_) => StatusCode::NOT_FOUND,
            ApiError::Internal(_) => StatusCode::INTERNAL_SERVER_ERROR,
            ApiError::Broker(_) => StatusCode::SERVICE_UNAVAILABLE,
        }
    }

    fn error_response(&self) -> HttpResponse<BoxBody> {
        let (code, message) = match self {
            ApiError::BadRequest(m) => ("bad_request", m.clone()),
            ApiError::Unauthorized(m) => ("unauthorized", m.clone()),
            ApiError::PayloadTooLarge(m) => ("payload_too_large", m.clone()),
            ApiError::NotFound(m) => ("not_found", m.clone()),
            ApiError::Internal(_) => ("internal", "internal server error".to_string()),
            ApiError::Broker(m) => ("broker_unavailable", m.clone()),
        };
        HttpResponse::build(self.status_code())
            .json(json!({ "error": { "code": code, "message": message } }))
    }
}