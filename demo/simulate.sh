#!/usr/bin/env bash
# Simulates an IoT weather device pushing readings to the backend.
#
# Usage:
#   ./demo/simulate.sh                 # single reading  ->  http://localhost:8080
#   ./demo/simulate.sh -n 50           # batch of 50
#   API_URL=http://host:8080 ./demo/simulate.sh -n 10
#
# Requires: curl. Default device API key matches db/init/002_seed.sh.
set -euo pipefail

API_URL="${API_URL:-http://localhost:8080}"
API_KEY="${DEVICE_API_KEY:-dev_demo_weather_station_2024}"
COUNT="${DEMO_COUNT:-10}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -n) COUNT="${2:?usage: -n <count>}"; shift 2 ;;
    -h|--help) echo "usage: $0 [-n <count>]"; exit 0 ;;
    *) API_URL="$1"; shift ;;
  esac
done

now() { date -u +%Y-%m-%dT%H:%M:%SZ; }
msgid() { cat /proc/sys/kernel/random/uuid 2>/dev/null || uuidgen 2>/dev/null || mktemp -u | sed 's/\.//g'; }
v() { awk -v a="$1" -v b="$2" -v r="$((RANDOM % 10000))" 'BEGIN{printf "%.1f", a + (r/10000)*(b-a)}'; }

counter=$((RANDOM % 300000))

reading_json() {
  counter=$((counter + RANDOM % 3))
  local t; t="$(now)"
  printf '{"message_id":"%s","taken_at":"%s","sensors":{' \
    "$(msgid)" "$t"
  printf '"temperature_c":%s,"humidity_pct":%s,"pressure_hpa":%s,' \
    "$(v 23 34)" "$(v 55 92)" "$(v 1002 1022)"
  printf '"windspeed_ms":%s,"wind_direction_deg":%s,"rain_counter":%s}}' \
    "$(v 0 11)" "$(v 0 360)" "$counter"
}

echo "Pushing $COUNT reading(s) -> $API_URL/api/v1/ingest/telemetry/batch"
echo "device api key: $API_KEY"

i=0
json=""
while [ "$i" -lt "$COUNT" ]; do
  r="$(reading_json)"
  json="$json$r,"
  i=$((i + 1))
done
json="{\"readings\":[${json%,}]}"

curl -sS -X POST "$API_URL/api/v1/ingest/telemetry/batch" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d "$json"
echo

# Idempotency demo: resend the exact same payload twice.
echo "--- resending identical batch (expect 202 both times, only first is stored)"
FIRST="$(mktemp)"
msgid > "$FIRST"
t="$(now)"
single="$(printf '{"message_id":"%s","taken_at":"%s","sensors":{"temperature_c":25.0,"humidity_pct":60.0,"pressure_hpa":1013.0,"windspeed_ms":2.0,"wind_direction_deg":90.0,"rain_counter":%s}}' "$(cat "$FIRST")" "$t" "$counter")"
echo "unique message_id: $(cat "$FIRST")"
curl -sS -o /dev/null -w "First  send -> HTTP %{http_code}\n" -X POST "$API_URL/api/v1/ingest/telemetry" \
  -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d "$single"
sleep 1
curl -sS -o /dev/null -w "Duplicate send -> HTTP %{http_code}\n" -X POST "$API_URL/api/v1/ingest/telemetry" \
  -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d "$single"
rm -f "$FIRST"

echo "Done."