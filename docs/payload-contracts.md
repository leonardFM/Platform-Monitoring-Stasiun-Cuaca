# Weather Station API — Payload Contracts (F.2)

Dokumen ini mendefinisikan struktur JSON untuk request/response seluruh endpoint.

---

## 1. Ingestion Response

### 1.1 Single Telemetry — Success
```json
{
  "accepted": true,
  "device_id": "WS-GRT-001",
  "ts": 1757308800,
  "seq": 10432
}
```
| Field | Type | Description |
|-------|------|-------------|
| `accepted` | boolean | Selalu `true` untuk single (validasi structural sudah lolos) |
| `device_id` | string | Echo dari payload untuk konfirmasi |
| `ts` | integer | Unix epoch detik dari payload |
| `seq` | integer | Nomor urut payload |

> **Catatan**: Response 202 (Accepted) — data diproses async via queue. Validasi range/error sensor dilakukan di worker, bukan di sini.

---

### 1.2 Batch Telemetry — Partial Success
```json
{
  "accepted": 8,
  "duplicates": 2,
  "items": [
    { "index": 0, "status": "accepted" },
    { "index": 1, "status": "accepted" },
    { "index": 2, "status": "duplicate" },
    { "index": 3, "status": "accepted" },
    { "index": 4, "status": "duplicate" },
    { "index": 5, "status": "accepted" },
    { "index": 6, "status": "accepted" },
    { "index": 7, "status": "accepted" },
    { "index": 8, "status": "accepted" },
    { "index": 9, "status": "accepted" }
  ]
}
```
| Field | Type | Description |
|-------|------|-------------|
| `accepted` | integer | Jumlah item yang dikirim ke queue (bukan duplikat) |
| `duplicates` | integer | Jumlah item yang sudah pernah dipersist (ON CONFLICT) |
| `items[].index` | integer | Indeks dalam array `batch` payload |
| `items[].status` | string | `accepted` \| `duplicate` |

> **Catatan**: Batch > 100 di-chunk otomatis ke beberapa job (maks 100 per job). Response 202.

---

### 1.3 Validation Error (400)
```json
{
  "error": {
    "code": "bad_request",
    "message": "payload.readings[2].s: unknown sensor code 'invalid_sensor'",
    "details": [
      { "field": "payload.readings[2].s", "message": "unknown sensor code 'invalid_sensor'" }
    ]
  },
  "request_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```
| Field | Type | Description |
|-------|------|-------------|
| `error.code` | string | Kode error: `bad_request`, `unauthorized`, `payload_too_large`, `service_unavailable` |
| `error.message` | string | Ringkasan error untuk logging |
| `error.details` | array | Detail per-field (opsional, untuk validasi struktur) |
| `request_id` | string | UUID untuk tracing |

---

## 2. Device CRUD

### 2.1 Create Device — Request
```json
{
  "device_code": "WS-GRT-002",
  "name": "Garut Barat",
  "location_id": "44444444-4444-4444-8444-444444444444",
  "status": "provisioned",
  "firmware_version": "1.4.2"
}
```

### 2.2 Create Device — Response (201)
```json
{
  "id": "dddddddd-0000-4000-8000-000000000002",
  "device_code": "WS-GRT-002",
  "name": "Garut Barat",
  "location_id": "44444444-4444-4444-8444-444444444444",
  "status": "provisioned",
  "firmware_version": "1.4.2",
  "created_at": "2026-09-20T10:00:00Z",
  "updated_at": "2026-09-20T10:00:00Z"
}
```

### 2.3 List Devices — Response (200)
```json
{
  "data": [
    { "id": "...", "device_code": "WS-GRT-001", "name": "Garut", "status": "active", ... },
    { "id": "...", "device_code": "WS-GRT-002", "name": "Garut Barat", "status": "provisioned", ... }
  ],
  "meta": { "total": 2, "page": 1, "per_page": 15 }
}
```

### 2.4 Get Device — Response (200)
```json
{
  "id": "dddddddd-0000-4000-8000-000000000001",
  "device_code": "WS-GRT-001",
  "name": "Garut",
  "location": { "id": "...", "name": "Garut", "latitude": -7.214, "longitude": 107.906, "altitude": 717 },
  "status": "active",
  "firmware_version": "1.4.2",
  "last_seen_at": "2026-09-20T10:30:00Z",
  "last_device_time": "2026-09-20T10:25:00Z",
  "created_at": "2026-09-20T09:00:00Z",
  "updated_at": "2026-09-20T09:00:00Z"
}
```

---

## 3. Sensor CRUD + Pemasangan + Kalibrasi

### 3.1 Sensor Type — List (GET /api/v1/sensor-types)
```json
{
  "data": [
    { "id": "...", "code": "temp_air", "name": "Suhu Udara", "unit": "°C", "min_value": -60, "max_value": 70 },
    { "id": "...", "code": "humidity", "name": "Kelembapan", "unit": "%", "min_value": 0, "max_value": 100 },
    { "id": "...", "code": "pressure", "name": "Tekanan Udara", "unit": "hPa", "min_value": 800, "max_value": 1100 },
    { "id": "...", "code": "wind_speed", "name": "Kecepatan Angin", "unit": "m/s", "min_value": 0, "max_value": 100 },
    { "id": "...", "code": "wind_dir", "name": "Arah Angin", "unit": "°", "min_value": 0, "max_value": 359 },
    { "id": "...", "code": "rain_counter", "name": "Curah Hujan (counter)", "unit": "tips", "min_value": 0, "max_value": null },
    { "id": "...", "code": "solar_rad", "name": "Radiasi Matahari", "unit": "W/m²", "min_value": 0, "max_value": 1400 }
  ]
}
```

### 3.2 Create Sensor — Request
```json
{
  "serial_number": "WS-TEMP-001",
  "sensor_type_id": "a0000000-0000-4000-8000-000000000001",
  "manufacturer": "Luweis WS",
  "model": "GRT-100",
  "status": "active"
}
```

### 3.3 Sensor Installation (Pasang/lepas) — Request
```json
POST /api/v1/devices/{device_id}/sensors
{
  "sensor_id": "a0000000-0000-4000-8000-000000000001",
  "installed_at": "2026-09-20T00:00:00Z"
}
```

### 3.4 Calibration — Request
```json
POST /api/v1/sensors/{sensor_id}/calibrations
{
  "offset": -0.2,
  "scale": 1.01,
  "effective_from": "2026-09-20T00:00:00Z",
  "effective_to": null
}
```

### 3.5 Calibration — Response
```json
{
  "id": "e0000000-0000-4000-8000-000000000001",
  "sensor_id": "a0000000-0000-4000-8000-000000000001",
  "offset": -0.2,
  "scale": 1.01,
  "effective_from": "2026-09-20T00:00:00Z",
  "effective_to": null,
  "created_at": "2026-09-20T10:00:00Z"
}
```

---

## 4. Time-Series Response (GET /api/v1/readings)

Format **columnar** untuk efisiensi (ukuran payload ~40% lebih kecil vs array of objects):

```json
{
  "sensor_id": "a0000000-0000-4000-8000-000000000001",
  "sensor_code": "temp_air",
  "interval": "1h",
  "unit": "°C",
  "columns": ["bucket_start", "min", "max", "avg", "sum", "count", "quality_count"],
  "data": [
    ["2026-09-20T00:00:00Z", 24.5, 28.1, 26.3, 1578.0, 60, 58],
    ["2026-09-20T01:00:00Z", 23.8, 27.5, 25.7, 1542.0, 60, 60]
  ],
  "meta": {
    "from": "2026-09-20T00:00:00Z",
    "to": "2026-09-20T23:00:00Z",
    "bucket_count": 24,
    "aggregated": true
  }
}
```

| Field | Description |
|-------|-------------|
| `columns` | Nama kolom (fix order) |
| `data` | Array of arrays — setiap inner array = 1 bucket |
| `aggregated` | `true` jika data dari `reading_aggregates`, `false` jika raw |

> **Alasan format columnar**: Mengurangi pengulangan nama field per row; cocok untuk charting library (Recharts, Chart.js) yang butuh array terpisah per series.

---

## 5. Standard Error Format (ErrorEnvelope)

Semua error response mengikuti envelope ini:

```json
{
  "error": {
    "code": "bad_request|unauthorized|forbidden|not_found|payload_too_large|service_unavailable",
    "message": "Human-readable summary",
    "details": [
      { "field": "payload.ts", "message": "ts must be a Unix epoch in seconds (integer)" },
      { "field": "payload.readings[0].v", "message": "value for temp_air must be a number" }
    ]
  },
  "request_id": "a1b2c3d4-e5f6-7890-abcd-ef1234567890"
}
```

| HTTP Status | `error.code` | Kapan dipakai |
|-------------|--------------|---------------|
| 400 | `bad_request` | Validasi JSON, field required, tipe data, enum, range |
| 401 | `unauthorized` | Header `X-API-Key` tidak ada / tidak valid |
| 403 | `forbidden` | (Reserved) API key valid tapi akses dilarang |
| 404 | `not_found` | Device/sensor/calibration tidak ditemukan |
| 413 | `payload_too_large` | Batch > 5000 item atau > max chunk |
| 503 | `service_unavailable` | RabbitMQ/DB down |

**Header**: Selalu termasuk `X-Request-ID: <uuid>` untuk tracing.