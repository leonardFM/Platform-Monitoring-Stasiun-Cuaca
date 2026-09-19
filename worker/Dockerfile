# syntax=docker/dockerfile:1

# ---------- build ----------
FROM rust:1.88-alpine AS build
RUN apk add --no-cache build-base
WORKDIR /app

COPY Cargo.toml Cargo.lock ./
COPY shared shared
COPY backend backend
COPY worker worker

RUN cargo build --release -p weather-worker

# ---------- runtime ----------
FROM alpine:3.20
RUN apk add --no-cache ca-certificates
COPY --from=build /app/target/release/weather-worker /usr/local/bin/weather-worker
ENTRYPOINT ["/usr/local/bin/weather-worker"]