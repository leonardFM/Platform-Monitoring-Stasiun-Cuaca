#!/bin/sh
# Seeds a demo device with an API key injected from the environment.
# Schema (001_schema.sql) is applied before this script by the image entrypoint.
set -e

psql -v ON_ERROR_STOP=1 --username "${POSTGRES_USER:-weather}" --dbname "${POSTGRES_DB:-weather_db}" <<-EOSQL
  INSERT INTO devices (name, location, api_key, calibration)
  VALUES (
    'Demo Station Alpha',
    '{"city":"Jakarta","country":"ID"}',
    '${DEMO_DEVICE_API_KEY:-dev_demo_weather_station_2024}',
    '{
      "temperature_c":   {"gain": 1.0, "offset": 0.0},
      "humidity_pct":    {"gain": 1.0, "offset": 0.0},
      "pressure_hpa":    {"gain": 1.0, "offset": 0.0},
      "windspeed_ms":    {"gain": 1.0, "offset": 0.0},
      "wind_direction_deg": {"gain": 1.0, "offset": 0.0}
    }'
  )
  ON CONFLICT (api_key) DO NOTHING;
EOSQL