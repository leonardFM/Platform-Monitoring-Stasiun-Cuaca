# IoT Weather Station Monitoring Platform

Platform monitoring stasiun cuaca IoT full-stack: Laravel ingestion API + RabbitMQ queue + Laravel worker (validasi, kalibrasi, quality flag, rain delta, agregat) + Next.js dashboard, semua diorkestrasi dengan Docker Compose.

---

## Quick Start

```bash
cp .env.example .env
docker compose up --build
```

**Development manual:**
```bash
# Frontend
cd frontend && npm install && npm run dev     # http://localhost:3000

# Backend (terminal terpisah)
cd laravel && composer install && php artisan migrate --seed && php artisan serve  # http://localhost:8080
```

---

## Services & Ports

| Service | URL |
|---------|-----|
| Frontend (Dashboard) | http://localhost:3000 |
| Backend API | http://localhost:8080 |
| **Swagger UI** | **http://localhost:8080/api/documentation** |
| OpenAPI Spec (JSON) | http://localhost:8080/docs |
| RabbitMQ Management | http://localhost:15672 (user: weather, pass: weather_password) |
| PostgreSQL | localhost:5432 |

`docker compose down` (tambah `-v` untuk hapus volume)

---

## Arsitektur

```
┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────────────┐     ┌─────────────┐
│   Sensor    │────▶│  Firmware    │────▶│  HTTPS/HTTP │────▶│  Nginx / Laravel │
│  Hardware   │     │  (buffer)    │     │  Transport  │     │  Ingest API      │
└─────────────┘     └──────────────┘     └─────────────┘     └────────┬─────────┘
                                                                       │
                                                                       ▼
┌─────────────┐     ┌──────────────┐     ┌─────────────┐     ┌──────────────────┐
│  Next.js    │◀───▶│  Read API    │◀───▶│ PostgreSQL  │◀───▶│  Laravel Worker  │
│  Dashboard  │     │  (/dashboard)│     │ + TimescaleDB│     │  (queue consumer)│
└─────────────┘     └──────────────┘     └─────────────┘     └────────┬─────────┘
                                                                       ▲
                                                                       │
                                                              ┌────────┴────────┐
                                                              │   RabbitMQ      │
                                                              │ (telemetry.ingest)│
                                                              └─────────────────┘
```

**Alur Data:**
1. Sensor → Firmware buffer → POST `/api/v1/ingest/telemetry/batch` (X-API-Key)
2. Middleware auth → Validasi struktural → Chunk batch (max 100) → RabbitMQ
3. Worker consume → Lookup sensor terpasang + kalibrasi → Hitung rain delta (advisory lock)
4. Kalibrasi `corrected = raw × scale + offset` → Quality flag (GOOD/OUT_OF_RANGE/SENSOR_ERROR/CLOCK_DRIFT/LATE/RAIN_*)
5. Insert `sensor_readings` (idempoten: `ON CONFLICT DO NOTHING`) + Update `device_health`
6. Cron 5 menit: Recalculate `reading_aggregates` (1m/1h/1d) dari reading GOOD
7. Frontend poll 30s → GET `/api/v1/dashboard` + `/readings` → Recharts

---

## Database (12 Tabel)

| Tabel | Fungsi |
|-------|--------|
| `users` | Admin internal |
| `locations` | Lokasi geografis (lat/lon/alt + CHECK constraint) |
| `devices` | Identitas device + lifecycle (soft delete, status state machine) |
| `device_credentials` | API key + secret hash (rotatable, revocable) |
| `device_status_history` | Audit log transisi status (enforced legal transitions) |
| `sensor_types` | Katalog: code, unit, valid_min/max, precision |
| `sensors` | Inventaris sensor fisik (serial_number, sensor_type_id) |
| `sensor_installations` | N:M device↔sensor dengan periode (partial unique index: 1 active install) |
| `sensor_calibrations` | Offset/scale per periode (`corrected = raw × scale + offset`) |
| `sensor_readings` | **TimescaleDB hypertable** narrow format (~184M rows/thn), partisi 7 hari, idempoten via `reading_key` |
| `reading_aggregates` | **TimescaleDB hypertable** pre-computed 1m/1h/1d (min/max/avg/sum/count), upsert idempoten |
| `device_health` | Health terkini (battery, RSSI, firmware, heartbeat) — 1:1 devices |

**Keputusan Desain Utama:**
- **Narrow schema**: 1 row per sensor per timestamp — extensible tanpa migrasi, kompresi TimescaleDB ~90%
- **UUID PK** semua entity, soft delete (`deleted_at`) untuk data historis
- **Quality flags** bukan rejection: data error tetap disimpan dengan flag untuk forensic
- **Idempotensi**: `reading_key = device_id|epoch_us|sensor_id|seq` + UNIQUE constraint
- **Rain delta**: Counter kumulatif (1 tip = 0.2mm), hitung delta dengan advisory lock per device, handle reset (RAIN_RESET) & first reading (RAIN_INITIAL)
- **Kalibrasi berbasis periode**: `effective_from`/`effective_to` untuk koreksi historis

---

## API Contracts (Ringkas)

**Ingest Single:**
```json
POST /api/v1/ingest/telemetry
Headers: X-API-Key: dev_demo_weather_station_2024
{
  "device_id": "WS-GRT-001",
  "fw": "1.4.2",
  "ts": 1757308800,
  "seq": 10432,
  "battery_v": 3.92,
  "rssi": -71,
  "readings": [
    { "s": "temp_air", "v": 27.4 },
    { "s": "humidity", "v": 82.1 },
    { "s": "pressure", "v": 1008.3 },
    { "s": "wind_speed", "v": 3.2 },
    { "s": "wind_dir", "v": 217 },
    { "s": "rain_counter", "v": 1043 },
    { "s": "solar_rad", "v": 512.7 }
  ]
}
```
Response: `202 Accepted` + `{"accepted": true, "device_id": "WS-GRT-001", "ts": 1757308800, "seq": 10432}`

**Field descriptions:**
| Field | Type | Description |
|-------|------|-------------|
| `device_id` | string | Kode device (harus cocok dengan API key) |
| `fw` | string | Firmware version |
| `ts` | integer | Unix epoch detik (UTC), waktu device |
| `seq` | integer | Nomor urut sejak boot, reset saat restart |
| `battery_v` | number | Voltase baterai (volt) |
| `rssi` | number | Signal strength (dBm) |
| `readings[]` | array | Array sensor `{s: code, v: value}` — sensor error tidak dikirim |

**Ingest Batch:** `POST /api/v1/ingest/telemetry/batch` (max 5000 items, chunk 100/job)
```json
{
  "device_id": "WS-GRT-001",
  "fw": "1.4.2",
  "batch": [
    { "ts": 1757308800, "seq": 10432, "battery_v": 3.92, "rssi": -71, "readings": [{ "s": "temp_air", "v": 27.4 }, { "s": "rain_counter", "v": 1043 }] },
    { "ts": 1757308860, "seq": 10433, "battery_v": 3.91, "rssi": -73, "readings": [{ "s": "temp_air", "v": 27.6 }, { "s": "rain_counter", "v": 1045 }] }
  ]
}
```
Response: `202 Accepted` + `{"accepted": 2, "duplicates": 0, "items": [{"index": 0, "status": "accepted"}, ...]}`

**Heartbeat:**
```json
POST /api/v1/ingest/heartbeat
{
  "device_id": "WS-GRT-001",
  "ts": 1757308920,
  "fw": "1.4.2",
  "battery_v": 3.90,
  "rssi": -70,
  "uptime_s": 864321
}
```

**Read Dashboard:** `GET /api/v1/dashboard` → ringkasan devices + latest readings + aggregates

**Read Time-Series:** `GET /api/v1/readings?device_id=&sensor_type=&from=&to=&interval=1h` → format columnar

**Swagger UI:** http://localhost:8080/api/documentation

---

## Frontend (Viewer)

- **Next.js 15** App Router + **Recharts** (line/bar/area/dual-axis)
- **Polling 30 detik** auto-refresh
- **Timezone WIB (Asia/Jakarta)** via `Intl.DateTimeFormat`
- **Gap handling**: `connectNulls=false` → garis putus (bukan nol) untuk data hilang
- **Tabs**: Overview (cards + charts + table), Devices (list + detail), Sensors, Health
- **Proxy API**: Next.js server-side proxy ke backend (`BACKEND_INTERNAL_URL`)

---

## Konfigurasi (.env)

| Variable | Deskripsi | Default |
|----------|-----------|---------|
| `APP_KEY` | Laravel app key (generate: `openssl rand -base64 32`) | **required** |
| `POSTGRES_USER/PASSWORD/DB` | PostgreSQL credentials | weather / weather_password / weather_db |
| `RABBITMQ_DEFAULT_USER/PASS/VHOST` | RabbitMQ credentials | weather / weather_password / / |
| `DEMO_DEVICE_API_KEY` | API key device demo (DEMO-001) | dev_demo_weather_station_2024 |
| `BACKEND_INTERNAL_URL` | Next.js → backend (Docker network) | http://backend:8080 |
| `NEXT_PUBLIC_API_URL` | Browser-facing API base | http://localhost:8080 |
| `MAX_BATCH_SIZE` | Max items per worker job | 100 |

Lihat `.env.example` untuk lengkapnya.

---

## Perintah Umum

```bash
# Status & logs
docker compose ps
docker compose logs -f worker
docker compose logs -f backend

# Database
docker compose exec backend php artisan migrate
docker compose exec backend php artisan migrate:fresh --seed

# Testing
docker compose exec backend php artisan test

# Lint PHP (dari root)
docker run --rm -v "$PWD/laravel:/app" -w /app php:8.4-cli-alpine \
  sh -lc 'for f in $(find app routes config bootstrap -name "*.php"); do php -l "$f"; done'

# Typecheck Frontend
cd frontend && npm run typecheck
```

---

## Simulator Device (Testing Cepat)

```bash
./demo/simulate.sh -n 50                    # kirim 50 batch ke localhost:8080
./demo/simulate.sh -n 50 http://host:8080   # custom endpoint
```

---

## Dokumentasi Lengkap

- `designDB.md` — Schema lengkap (kolom, tipe, constraint, index, FK, relasi)
- `docs/database.md` — Rationale desain, estimasi growth, wide vs narrow
- `docs/erd/weather_station.dbml` — ERD source (dbdiagram.io)
- `docs/erd/weather_station.png` — ERD rendered
- `docs/payload-contracts.md` — Request/response contracts detail + error envelope
- `docs/data-flow.md` — Sequence diagram + flowchart (Sensor → Frontend)
- `JAWABAN.md` — Essays 8 soal F.3 + design questions A/B/C/D/E/G