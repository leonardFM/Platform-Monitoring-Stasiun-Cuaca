# syntax=docker/dockerfile:1

# ---------- build ----------
FROM rust:1.88-alpine AS build
RUN apk add --no-cache build-base
WORKDIR /app

COPY Cargo.toml Cargo.lock ./
COPY shared shared
COPY backend backend
COPY worker worker

RUN cargo build --release -p weather-backend

# ---------- runtime ----------
FROM alpine:3.20
RUN apk add --no-cache ca-certificates
COPY --from=build /app/target/release/weather-backend /usr/local/bin/weather-backend
EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/weather-backend"]