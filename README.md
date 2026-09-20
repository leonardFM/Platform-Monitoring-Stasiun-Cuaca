# IoT Weather Station Monitoring Platform

Full-stack take-home project: a Laravel ingestion API + RabbitMQ telemetry
queue + Laravel worker (validation, calibration, quality, rain delta,
aggregates) + Next.js dashboard, all orchestrated with Docker Compose.

## Architecture

```
Weather Device
     │  POST /api/v1/ingest/telemetry(+batch)   X-API-Key
     ▼
Laravel backend ──> RabbitMQ (telemetry.ingest) ──> Laravel worker ──> PostgreSQL
     │                                                          │  aggregates
     │  read API /api/v1/dashboard                              │
     ▼                                                          ▼
Next.js (dashboard) ─────────────────────────────────────>  PostgreSQL (read)
```

Backend and worker run the same Laravel codebase (`./laravel`) as two Docker
Compose services, so the pipeline is plain PHP jobs on the default queue.

## Quick start

```bash
cp .env.example .env
docker compose up --build
```

Services (with the ports exposed to the host):

| Service  | URL                                            |
|----------|------------------------------------------------|
| frontend | http://localhost:3000                          |
| backend  | http://localhost:8080                          |
| Swagger UI | http://localhost:8080/api/documentation    |
| OpenAPI spec | http://localhost:8080/docs |
| RabbitMQ management | http://localhost:15672 (weather / weather_password) |
| Postgres | localhost:5432                                 |

`docker compose down` (add `-v` to also wipe the named volumes).

## Configuration

Everything is configured via environment variables (see `.env.example`).
Compose refuses to start unless the required secrets are present, so no
credentials are hardcoded anywhere.

| Variable | Description |
|----------|-------------|
| `POSTGRES_USER/PASSWORD/DB` | Postgres credentials |
| `RABBITMQ_DEFAULT_USER/PASS/VHOST` | RabbitMQ credentials/vhost |
| `DEMO_DEVICE_API_KEY` | seeded demo device API key |
| `DB_HOST/PORT/USER/PASSWORD/NAME` | Laravel -> Postgres |
| `RABBITMQ_HOST/PORT/USER/PASSWORD/VHOST` | Laravel -> RabbitMQ |
| `BACKEND_INTERNAL_URL` | Next.js -> backend (Docker network) |
| `NEXT_PUBLIC_API_URL` | the browser-facing API base |

Internal connections use service names (`postgres:5432`, `rabbitmq:5672`,
`backend:8080`); healthchecks gate service startup with `depends_on:
condition: service_healthy` (no `sleep` anywhere).

## Ingest API

Authenticate with the header `X-API-Key: dev_demo_weather_station_2024`
(default seeded device).

```json
{
  "message_id": "5b1b1a3e-...",
  "taken_at": "2026-01-01T12:00:00Z",
  "sensors": {
    "temperature_c": 25.4,
    "humidity_pct": 62.0,
    "pressure_hpa": 1013.2,
    "windspeed_ms": 3.1,
    "wind_direction_deg": 180.0,
    "rain_counter": 123456
  }
}
```

```bash
curl -X POST http://localhost:8080/api/v1/ingest/telemetry \
  -H "Content-Type: application/json" \
  -H "X-API-Key: dev_demo_weather_station_2024" \
  -d '{"message_id":"5b1b1a3e-...","taken_at":"2026-01-01T12:00:00Z","sensors":{ ... }}'
```

Batch endpoint (max 100 readings per request; readings are validated and
pushed individually):

```bash
curl -X POST http://localhost:8080/api/v1/ingest/telemetry/batch \
  -H "Content-Type: application/json" \
  -H "X-API-Key: dev_demo_weather_station_2024" \
  -d '{"readings":[ {...}, {...} ]}'
```

Or run the simulator:

```bash
./demo/simulate.sh -n 50
./demo/simulate.sh -n 50 http://localhost:8080
```

Other endpoints: `GET /healthz`, `GET /api/v1/dashboard`, plus the interactive
Swagger UI at `/api/documentation` (raw spec: `/docs`).

Swagger "Try it out" works out of the box: the ingest operations document a
pre-filled `X-API-Key` header parameter with the demo key and ship valid example
bodies, so hitting **Execute** returns `202` without extra setup (clear the key
field to see `401`).

## Worker processing pipeline

1. Payload decoded from RabbitMQ (`telemetry.ingest`)
2. Sensor validation — device must exist and be active
3. Range validation — strict physical bounds per channel (`-60..60 °C`, …)
4. Idempotency — `INSERT ... ON CONFLICT (device_id, message_id) DO NOTHING`;
   duplicates are acked and skipped
5. Calibration — `value = raw * gain + offset` from the device's calibration
   config (absent entries are identity)
6. Quality flags + score (100 for all-valid, scaled by out-of-range channels,
   `rain_initial` / `rain_reset` deduct)
7. `rain_counter` processing — delta versus the device's previous reading,
   guarded by a per-device advisory lock; handles resets and out-of-order
   arrivals
8. DB insert + hourly/daily aggregate upsert (running avg/min/max, rain sum)
9. Periodic aggregate recalculation from base readings (self-healing, every 5
   minutes); graceful shutdown drains the consumer before closing

Delivery semantics: publisher confirms on the backend; worker uses prefetch 1
and acks only after a successful commit.

## Project layout

```
laravel/    Laravel app (API, queue job/worker pipeline, Swagger docs, Docker image)
frontend/   Next.js App Router dashboard (server-side proxy to backend)
demo/       curl-based device simulator
```

## Verifying

```bash
docker compose ps                  # all healthy
docker compose logs -f worker      # show ingested readings
curl -s http://localhost:8080/api/v1/dashboard | python3 -m json.tool
```

## Development

```bash
# lint all PHP files
docker run --rm -v "$PWD/laravel:/app" -w /app php:8.4-cli-alpine \
  sh -lc 'for f in $(find app routes config bootstrap -name "*.php"); do php -l "$f"; done'
```