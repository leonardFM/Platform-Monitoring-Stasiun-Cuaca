# IoT Weather Station Monitoring Platform

Full-stack take-home project: an Actix-Web ingestion API + RabbitMQ telemetry
queue + Rust worker (validation, calibration, quality, rain delta, aggregates)
+ Next.js dashboard, all orchestrated with Docker Compose.

## Architecture

```
Weather Device
     │  POST /api/v1/ingest/telemetry(+batch)   X-API-Key
     ▼
Actix-Web backend ──> RabbitMQ (telemetry.ingest) ──> Rust worker ──> PostgreSQL
     │                                                          │  aggregates
     │  read API /api/v1/dashboard                              │
     ▼                                                          ▼
Next.js (dashboard) ─────────────────────────────────────>  PostgreSQL (read)
```

Data flow: devices post JSON -> backend **validates + authenticates** and
publishes to RabbitMQ -> worker consumes, runs the processing pipeline and
persists -> dashboard reads aggregates/readings.

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
| `DATABASE_URL` | backend/worker -> postgres |
| `RABBITMQ_URL` / `RABBITMQ_QUEUE` | backend/worker -> rabbitmq |
| `RUST_LOG` | backend/worker log level |
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

Batch endpoint (max 100 readings, atomic validation):

```bash
curl -X POST http://localhost:8080/api/v1/ingest/telemetry/batch \
  -H "Content-Type: application/json" \
  -H "X-API-Key: dev_demo_weather_station_2024" \
  -d '{"readings":[ {...}, {...} ]}'
```

Or run the simulator:

```bash
docker compose exec backend /bin/true   # not needed
# from the host:
./demo/simulate.sh -n 50
./demo/simulate.sh -n 50 http://localhost:8080
```

Other endpoints: `GET /healthz`, `GET /api/v1/dashboard`.

## Worker processing pipeline

1. Deserialize + decode `IngestMessage`
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
backend/    Actix-Web API (auth, validation, RabbitMQ publisher)
worker/     Tokio consumer (processing pipeline, aggregates)
shared/     serde DTOs + calibration + sensor bounds (shared by both crates)
db/init/    Postgres schema + demo device seed (entrypoint-initdb.d)
frontend/   Next.js App Router dashboard (server-side proxy to backend)
demo/       curl-based device simulator
```

## Verifying

```bash
docker compose ps                  # all healthy
docker compose logs -f worker      # show ingested readings
curl -s http://localhost:8080/api/v1/dashboard | python3 -m json.tool
```