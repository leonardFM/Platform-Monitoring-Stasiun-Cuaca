#!/usr/bin/env bash
# Simulates an IoT weather device pushing readings to the backend (F.1 format).
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
DEVICE_ID="${DEVICE_ID:-DEMO-001}"
FW="${FW:-1.4.2}"

while [[ $# -gt 0 ]]; do
  case "$1" in
    -n) COUNT="${2:?usage: -n <count>}"; shift 2 ;;
    -h|--help) echo "usage: $0 [-n <count>]"; exit 0 ;;
    *) API_URL="$1"; shift ;;
  esac
done

epoch() { date -u +%s; }
v() { awk -v a="$1" -v b="$2" -v r="$((RANDOM % 10000))" 'BEGIN{printf "%.1f", a + (r/10000)*(b-a)}'; }

counter=$((RANDOM % 300000))
seq=10000

reading_json() {
  local ts; ts=$(epoch)
  counter=$((counter + RANDOM % 3))
  seq=$((seq + 1))
  printf '{"ts":%d,"seq":%d,"battery_v":%.2f,"rssi":%d,"readings":[' \
    "$ts" "$seq" "$(awk 'BEGIN{printf "%.2f", 3.7 + (rand()*0.3)}')" "$(( -90 + RANDOM % 30 ))"
  printf '{"s":"temp_air","v":%.1f},' "$(v -10 40)"
  printf '{"s":"humidity","v":%.1f},' "$(v 30 95)"
  printf '{"s":"pressure","v":%.1f},' "$(v 980 1030)"
  printf '{"s":"wind_speed","v":%.1f},' "$(v 0 15)"
  printf '{"s":"wind_dir","v":%d},' "$(( RANDOM % 360 ))"
  printf '{"s":"rain_counter","v":%d},' "$counter"
  printf '{"s":"solar_rad","v":%.1f}' "$(v 0 800)"
  printf ']}'
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
json="{\"device_id\":\"$DEVICE_ID\",\"fw\":\"$FW\",\"batch\":[${json%,}]}"

curl -sS -X POST "$API_URL/api/v1/ingest/telemetry/batch" \
  -H "Content-Type: application/json" \
  -H "X-API-Key: $API_KEY" \
  -d "$json"
echo

# Idempotency demo: resend the exact same payload twice.
echo "--- resending identical batch (expect 202 both times, only first is stored)"
ts=$(epoch)
single="$(printf '{"device_id":"%s","fw":"%s","ts":%d,"seq":%d,"battery_v":3.85,"rssi":-65,"readings":[{"s":"temp_air","v":25.0},{"s":"humidity","v":60.0},{"s":"pressure","v":1013.0},{"s":"wind_speed","v":2.0},{"s":"wind_dir","v":90},{"s":"rain_counter","v":%d},{"s":"solar_rad","v":500.0}]}' "$DEVICE_ID" "$FW" "$ts" "$seq" "$counter")"
echo "unique ts+seq: $ts + $seq"
curl -sS -o /dev/null -w "First  send -> HTTP %{http_code}\n" -X POST "$API_URL/api/v1/ingest/telemetry" \
  -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d "$single"
sleep 1
curl -sS -o /dev/null -w "Duplicate send -> HTTP %{http_code}\n" -X POST "$API_URL/api/v1/ingest/telemetry" \
  -H "Content-Type: application/json" -H "X-API-Key: $API_KEY" -d "$single"

echo "Done."