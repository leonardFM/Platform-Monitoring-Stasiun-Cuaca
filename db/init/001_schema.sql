-- Weather Station Monitoring Platform
-- Initial schema. Applied by the postgres image on first boot.

CREATE TABLE IF NOT EXISTS devices (
    id          UUID PRIMARY KEY DEFAULT gen_random_uuid(),
    name        TEXT        NOT NULL,
    location    JSONB       NOT NULL DEFAULT '{}',
    api_key     TEXT        NOT NULL UNIQUE,
    calibration JSONB       NOT NULL DEFAULT '{}',
    is_active   BOOLEAN     NOT NULL DEFAULT TRUE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT now()
);

CREATE TABLE IF NOT EXISTS telemetry_readings (
    id                 BIGSERIAL PRIMARY KEY,
    device_id          UUID        NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    message_id         UUID        NOT NULL,
    taken_at           TIMESTAMPTZ NOT NULL,
    received_at        TIMESTAMPTZ NOT NULL DEFAULT now(),
    temperature_c      REAL,
    humidity_pct       REAL,
    pressure_hpa       REAL,
    windspeed_ms       REAL,
    wind_direction_deg REAL,
    rain_counter       BIGINT,
    rain_delta_mm      DOUBLE PRECISION NOT NULL DEFAULT 0,
    quality_flags      JSONB       NOT NULL DEFAULT '[]',
    quality_score      SMALLINT    NOT NULL DEFAULT 100,
    CONSTRAINT uq_telemetry_device_message UNIQUE (device_id, message_id)
);

CREATE INDEX IF NOT EXISTS idx_telemetry_device_taken_at ON telemetry_readings (device_id, taken_at DESC);
CREATE INDEX IF NOT EXISTS idx_telemetry_taken_at ON telemetry_readings (taken_at DESC);

CREATE TABLE IF NOT EXISTS station_aggregates (
    id                 BIGSERIAL PRIMARY KEY,
    device_id          UUID        NOT NULL REFERENCES devices(id) ON DELETE CASCADE,
    granularity        TEXT        NOT NULL CHECK (granularity IN ('hour', 'day')),
    period_start       TIMESTAMPTZ NOT NULL,
    count              BIGINT      NOT NULL DEFAULT 0,
    temperature_avg    REAL,
    temperature_min    REAL,
    temperature_max    REAL,
    humidity_avg       REAL,
    humidity_min       REAL,
    humidity_max       REAL,
    pressure_avg       REAL,
    pressure_min       REAL,
    pressure_max       REAL,
    windspeed_avg      REAL,
    windspeed_min      REAL,
    windspeed_max      REAL,
    wind_direction_avg REAL,
    rain_total_mm      DOUBLE PRECISION NOT NULL DEFAULT 0,
    CONSTRAINT uq_device_period UNIQUE (device_id, granularity, period_start)
);

CREATE INDEX IF NOT EXISTS idx_aggregates_period ON station_aggregates (granularity, period_start);