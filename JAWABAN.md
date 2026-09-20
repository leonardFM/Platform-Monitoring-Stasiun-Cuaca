# JAWABAN.md — Luwes Inovasi Mandiri Technical Test

---

## Bagian 5: 8 Soal Desain (F.3)

### 1. Payload dengan `ts` di masa depan (device clock salah, maju 2 jam)
**Penanganan**: Diterima (202), diberi quality flag `CLOCK_DRIFT` di `sensor_readings`. Di `TelemetryProcessor::clockFlag()`, jika `device_time > now() + 5 menit`, flag `CLOCK_DRIFT` ditambahkan. Data tetap diproses dan disimpan agar tidak kehilangan data historis; frontend bisa filter/tampilkan warning. Alasan: device clock bisa tidak sinkron NTP, menolak akan kehilangan data valid.

### 2. `temp_air` bernilai -999 (kode error sensor)
**Penanganan**: Di `TelemetryProcessor::qualityAndCalibrate()`, nilai `-999` dideteksi sebagai `SENSOR_ERROR`. `corrected_value` diset `NULL`, `quality_flag = 'SENSOR_ERROR'`. Data raw tetap disimpan (`raw_value = -999`) untuk audit, tapi tidak masuk agregat (AggregateRecalculator mengecualikan `rain_counter` dan `quality_flag != 'GOOD'`). Frontend menampilkan "Sensor Error" bukan angka.

### 3. `humidity` bernilai 150 (di luar rentang 0–100)
**Penanganan**: Di `qualityAndCalibrate()`, rentang sensor dicek dari `sensorBounds()` (humidity: 0–100). Jika di luar, `quality_flag = 'OUT_OF_RANGE'`, `corrected_value` tetap dihitung (raw × scale + offset). Data masuk agregat hanya jika `quality_flag = 'GOOD'`, jadi `OUT_OF_RANGE` diekscludekan dari min/max/avg harian. Frontend bisa tampilkan dengan warna merah/tooltip "Out of range".

### 4. `rain_counter` turun dari 1043 → 5 (device restart)
**Penanganan**: Di `TelemetryProcessor::rainValue()`, state counter sebelumnya diambil dari DB (`rainBaseline`). Jika `current < state`, dikembalikan `RAIN_RESET` dengan `corrected_value = 0 mm` (tidak minus). Counter baru (5) disimpan sebagai baseline berikutnya. Logika: tip hujan tidak bisa "un-happen"; restart device reset counter ke 0, jadi delta dihitung dari 0.

### 5. Payload sama dikirim 3× (device_id + ts identik)
**Penanganan**: Idempotensi di level DB: `sensor_readings` punya `UNIQUE (reading_key, device_time)` di mana `reading_key = device_id|device_time_us|sensor_id|seq`. `INSERT ... ON CONFLICT (reading_key, device_time) DO NOTHING`. Di `IngestController::alreadyPersisted()` juga ada pre-check best-effort sebelum dispatch ke queue. Hasil: 3 request → 202, tapi hanya 1 row tersimpan per sensor.

### 6. `device_id` tidak terdaftar
**Penanganan**: Middleware `AuthenticateApiKey` memvalidasi `X-API-Key` ke `device_credentials`. Jika key tidak ada → 401. Jika key valid tapi `payload.device_id` ≠ `device.device_code` DAN ≠ `device.id` → 400 `payload device_id '...' does not match authenticated device`. Device tidak terdaftar = tidak punya credential → 401 di middleware, bukan 404 (hindari info enumeration).

### 7. Sensor `solar_rad` tidak ada di array `readings`
**Penanganan**: Firmware **tidak mengirim** sensor yang error/offline. Di `TelemetryProcessor`, loop hanya iterasi `item['readings']` (sensor yang dikirim). Sensor terpasang tapi tidak dikirim → **diabaikan** (tidak insert NULL, tidak insert row sama sekali). Artinya: `solar_rad` tidak ada di payload = tidak ada row untuk timestamp ts itu. Frontend time-series akan ada gap (tidak ada bucket untuk timestamp itu), bukan nilai 0.

### 8. Batch berisi 500 record
**Penanganan**: 
- `IngestController::batch()` validasi `max_request_items = 5000` (config), tolak 413 jika melebihi.
- Items di-chunk per `max_batch_size = 100` (config) → 5 job ke RabbitMQ (`ProcessTelemetryReading`).
- Setiap job diproses serial per device (advisory lock `pg_advisory_xact_lock`) supaya rain counter delta aman.
- Response 202 dengan `{ accepted: 500, duplicates: 0, items: [...] }` — setiap item status `accepted`/`duplicate`.
- Transaksi: tiap job = 1 transaksi DB; chunking memastikan transaksi tidak terlalu besar (lock contention).

---

## Bagian A: Arsitektur Data Flow (Sensor → Frontend)

1. **Sensor → Firmware Buffering**: Sensor baca tiap detik/menit, firmware akumulasi di RAM/flash (ring buffer). Saat online, kirim batch (max 500) via HTTP POST ke `/ingest/telemetry/batch`; offline → buffer lokal.
2. **Transport**: HTTPS (production) atau HTTP (local) ke API Gateway → Laravel backend.
3. **Autentikasi**: Middleware `AuthenticateApiKey` cek `X-API-Key` ke `device_credentials`, resolve `Device` model, attach ke request.
4. **Validasi**: `JsonParser` parse & normalisasi payload F.1 (single/batch/heartbeat). Validasi struktural (required fields, enum sensor code, tipe data). Range validasi **bukan** di sini (jadi quality flag).
5. **Normalisasi/Dedup**: `IngestController` cek idempotensi best-effort (SELECT sensor_readings), chunk batch >100, dispatch ke RabbitMQ queue `telemetry.ingest`.
5. **Enrichment (Worker)**: `ProcessTelemetryReading` job di worker → `TelemetryProcessor`:
   - Lookup sensor terpasang + kalibrasi efektif (periode `effective_from/to`).
   - Rain delta (0.2 mm/tip), handle reset/restart (`RAIN_RESET`, `RAIN_INITIAL`).
   - Kalibrasi: `corrected = raw × scale + offset`.
   - Quality flag: `GOOD`, `OUT_OF_RANGE`, `SENSOR_ERROR`, `CLOCK_DRIFT`, `LATE`.
   - Insert ke `sensor_readings` (TimescaleDB hypertable, partition 7 hari).
   - Update `device_health` + `devices.last_seen_at/last_device_time`.
6. **Agregasi**: Scheduler `aggregates:recalculate` tiap 5 menit → `AggregateRecalculator` rebuild `reading_aggregates` (1m/1h/1d) dari `sensor_readings` (exclude rain_counter, hanya GOOD).
7. **API → Frontend**: GET `/readings` (interval raw/1m/1h/1d), `/readings/latest`, `/readings/summary`, `/dashboard` → query `reading_aggregates` + `sensor_readings` (recent).
8. **Frontend**: Recharts line/bar chart, polling 30s, WIB timezone.

---

## Bagian B: Trade-off Wide vs Narrow Schema

**Narrow (dipakai)**: `sensor_readings` 1 row per sensor per timestamp.
- Pro: Fleksibel (tambah sensor type tanpa migrasi), kompresi TimescaleDB efisien per kolom, query time-series cepat (index sensor_id + time).
- Contra: Lebih banyak row (7× per timestamp), join butuh `sensor_installations` + `sensor_types`.

**Wide (legacy)**: `telemetry_readings` 1 row per timestamp dengan kolom per sensor.
- Pro: 1 row per device-timestamp, query simple.
- Contra: Schema rigid (tambah sensor = ALTER TABLE), kolom sparse (NULL banyak), kompresi kurang efisien, migrasi sulit.

Keputusan: **Narrow** — cocok untuk IoT time-series volume tinggi (184 jt row/tahun), TimescaleDB native compression per column, extensible tanpa downtime.

---

## Bagian C: Idempotensi & Race Condition

- **Reading Key**: `device_id|device_time_us|sensor_id|seq` — unik per sensor per device per timestamp per sequence.
- **DB Constraint**: `UNIQUE (reading_key, device_time)` di `sensor_readings` (TimescaleDB support unique index dengan partition column).
- **Worker Lock**: `pg_advisory_xact_lock(hashtext(device_id))` serialisasi per device → rain delta aman dari race condition antar worker.
- **Batch Dedup**: `IngestController::alreadyPersisted()` pre-check sebelum dispatch (best-effort, final guard di DB).

---

## Bagian D: Rain Counter Logic

- 1 tip = 0.2 mm.
- `rain_counter` kumulatif, reset ke 0 saat restart.
- `rainBaseline()` ambil last raw_value < min_ts batch.
- `rainValue()`:
  - First ever → `RAIN_INITIAL`, 0 mm.
  - Counter naik → delta × 0.2 mm, flag `GOOD`.
  - Counter turun → `RAIN_RESET`, 0 mm (tidak negatif).
  - -999 → `SENSOR_ERROR`, NULL corrected.

---

## Bagian E: API Design Decisions

- **Cursor pagination** untuk time-series: `?cursor=<base64(last_bucket_start,last_sensor_id)>&limit=1000` — stabil saat data baru masuk, efisien index seek.
- **Rate limiting** per device (API key) di ingestion: 100 req/menit (config), 429 + `Retry-After`.
- **Request ID**: Middleware generate UUID per request, inject ke log & response header `X-Request-ID`.
- **Timezone**: DB simpan UTC, API terima UTC, frontend paksa WIB (Asia/Jakarta) via `Intl.DateTimeFormat`.

---

## Bagian E (lanjut): Growth Estimation

- 1 device, 7 sensor, 1 menit interval → 7 × 60 × 24 × 365 ≈ 3.67 juta rows/tahun.
- 50 device → ~184 juta rows/tahun.
- TimescaleDB compression (Gorilla + delta-delta) ~90% untuk `raw_value`/`corrected_value` numeric.
- Retention policy: raw 2 tahun, aggregates 1m/1h/1d forever (lebih kecil).
- Index: `(sensor_id, device_time DESC)` untuk query latest; `(device_id, device_time DESC)` untuk device history.

---

## Bagian G: Frontend Considerations

- **Chart gaps**: `readings` interval mengembalikan `null` bucket → Recharts `connectNulls=false` → garis putus (bukan 0).
- **Polling**: 30s interval, `stale-while-revalidate` pattern.
- **Mobile**: Responsive grid, chart horizontal scroll pada layar kecil.
- **Error/Empty/Loading**: Skeleton loader, toast error dengan `request_id` untuk support.

---

*Dokumen ini di-generate sebagai bagian dari Fase 3 & 4 deliverable.*