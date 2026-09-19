# Desain Database Sistem Stasiun Cuaca

Dokumen ini menjelaskan skema database PostgreSQL untuk sistem stasiun cuaca.
Implementasinya berupa **migration Laravel** di `laravel/database/migrations/`
dan **seeder** di `laravel/database/seeders/`. ERD interaktif dapat dilihat di
`docs/erd/weather_station.dbml` (render: `docs/erd/weather_station.png`).

Semua timestamp disimpan dalam **UTC** (`timestamp with time zone`). Stempel waktu
utama sebuah pembacaan adalah `device_time` (waktu sensor), bukan waktu penerimaan
server.

---

## 1. Ringkasan

Hanya **11 tabel inti** yang wajib; `device_health` ditambahkan sebagai tabel
terpisah (opsional tetapi direkomendasikan) supaya metadata heartbeat tidak
membengkakkan tabel `devices`.

```
users/ locations/ sensor_types/                     (data master)
        devices/                                    (identitas & lifecycle device)
                device_credentials/                 (auth device)
                device_status_history/              (riwayat transisi status)
                sensor_installations/               (relasi device <-> sensor, N:M)
                sensor_calibrations/                (periode kalibrasi sensor)
                device_health/                      (health/heartbeat terkini)
        sensor_readings/                            (narrow-form time-series, dipartisi)
                sensor_types (via sensors)
        reading_aggregates/                         (agregat 1m/1h/1d)
```

Aturan desain yang dijamin oleh skema:

1. **Forensik & riwayat tidak boleh hilang.** Tidak ada `ON DELETE CASCADE` untuk
   data historis; semua relasi ke data transaksional memakai `RESTRICT`. Device dan
   sensor memakai **soft delete** (`deleted_at`), bukan `DELETE`.
2. **Nilai mentah (raw) tidak boleh diubah.** `sensor_readings` bersifat
   append-only; nilai yang non-ilmiah tetap disimpan namun ditandai
   `quality_flag = OUT_OF_RANGE` / `SENSOR_ERROR`, bukan dibuang.
3. **Kalibrasi berbasis periode.** Hasil terkalibrasi dihitung
   `corrected = raw * scale + offset` menggunakan kalibrasi yang berlaku saat
   `device_time` (lihat `sensor_calibrations.effective_from/effective_to`).
4. **Duplikasi idempoten di level database.** Setiap baris di
   `sensor_readings` memiliki `reading_key` deterministik + `UNIQUE`, sehingga
   `INSERT ... ON CONFLICT (reading_key, device_time) DO NOTHING` menjamin
   pengiriman ulang data (retry) tidak menggandakan baris.
5. **Delta perbedaan jenis sensor.** Nilai "delta" (mis. rainy increment) selalu
   dihitung terhadap pembacaan sebelumnya pada interval monitoring, bukan dari
   sistem; format tersimpan adalah *narrow* (satu baris per `sensor_id`) sehingga
   jenis sensor baru tidak memerlukan ubah skema.
6. **Data out-of-range tidak dibuang** secara diam-diam; dicek terhadap
   `sensor_types.valid_min/valid_max`.

---

## 2. Definisi Tabel

### 2.1 users
Akun pengguna internal (pengelola sistem, admin).

| Kolom          | Tipe            | Keterangan                            |
|----------------|-----------------|---------------------------------------|
| `id`           | `uuid` (PK)     | `gen_random_uuid()`                   |
| `name`         | `varchar(255)`  | Nama pengguna                         |
| `email`        | `varchar(255)`  | `UNIQUE`, login identifier            |
| `password_hash`| `text`          | Hash password (tanpa plaintext)       |
| `created_at`   | `timestamptz`   | `default now()`                       |
| `updated_at`   | `timestamptz`   | `default now()`                       |
| `deleted_at`   | `timestamptz`   | Soft delete                           |

Relasi: `users.id < device_status_history.changed_by` (nullable).

### 2.2 locations
Lokasi geografis stasiun.

| Kolom        | Tipe           | Keterangan                           |
|--------------|----------------|--------------------------------------|
| `id`         | `uuid` (PK)    |                                      |
| `name`       | `varchar(255)` | Nama lokasi                          |
| `latitude`   | `numeric(9,6)` | `CHECK -90..90`                      |
| `longitude`  | `numeric(9,6)` | `CHECK -180..180`                    |
| `altitude`   | `numeric(10,2)`| Tinggi (m), nullable                 |
| `created_at` / `updated_at` | `timestamptz` | `default now()`          |

Relasi: `locations.id <- devices.location_id`.

### 2.3 devices
Identity + lifecycle stasiun. Ruas `status` dicek hanya terhadap 4 nilai.

| Kolom              | Tipe           | Keterangan                          |
|--------------------|----------------|-------------------------------------|
| `id`               | `uuid` (PK)    |                                     |
| `device_code`      | `varchar(100)` | `UNIQUE`, kode perangkat            |
| `name`             | `varchar(255)` | Nama stasiun                        |
| `location_id`      | `uuid` → `locations.id` (FK) | Lokasi terpasang          |
| `status`           | `varchar(30)`  | `CHECK IN ('provisioned','active','maintenance','decommissioned')` |
| `firmware_version` | `varchar(50)`  | nullable                            |
| `last_seen_at`     | `timestamptz`  | Heartbeat terakhir, nullable        |
| `last_device_time` | `timestamptz`  | `device_time` terakhir, nullable    |
| `created_at`/`updated_at`/`deleted_at` | `timestamptz` | soft delete |

Index: `location_id`, `status`, `last_seen_at`, `(status, last_seen_at)`.

**Lifecycle (legal transition, dijamin juga oleh `device_status_history`):**

```
NULL ──> provisioned ──> active ──> decommissioned
                         ^   |
                         |   v
                      maintenance
```

Transisi yang **tidak** diizinkan: `decommissioned` ke status apa pun,
`provisioned ─> maintenance|decommissioned` langsung, `maintenance ─> provisioned`.

- `provisioned`: device baru dibuat, belum aktif.
- `active`: menunggu/mengirim data.
- `maintenance`: sedang perbaikan; data boleh di-buffer.
- `decommissioned`: pensiun; **device yang sudah pensiun tidak boleh mengirim
  telemetri baru** (dijamin pada lapisan API).

### 2.4 device_credentials
Kredensial autentikasi per device (terpisah dari ruas identity agar `devices`
tetap ramping).

| Kolom          | Tipe           | Keterangan                           |
|----------------|----------------|--------------------------------------|
| `id`           | `uuid` (PK)    |                                      |
| `device_id`    | `uuid` → `devices.id` (FK, RESTRICT) | |
| `api_key`      | `varchar(255)` | `UNIQUE`                              |
| `secret_hash`  | `text`         | Hash rahasia (tanpa plaintext)        |
| `created_at`   | `timestamptz`  | `default now()`                      |
| `last_used_at` | `timestamptz`  | nullable                             |
| `revoked_at`   | `timestamptz`  | nullable                             |

Index: `device_id`, `(device_id, revoked_at)`. Rotasi kredensial dilakukan dengan
menambah baris baru lalu mencabut baris lama (`revoked_at`).

### 2.5 device_status_history
Buku besar (append-only) setiap transisi status — sumber kebenaran lifecycle.

| Kolom        | Tipe           | Keterangan                          |
|--------------|----------------|-------------------------------------|
| `id`         | `uuid` (PK)    |                                     |
| `device_id`  | `uuid` → `devices.id` (FK, RESTRICT) | |
| `from_status`| `varchar(30)`  | NULL untuk baris pertama            |
| `to_status`  | `varchar(30)`  | NOT NULL                           |
| `reason`     | `text`         | Alasan transisi, nullable           |
| `changed_by` | `uuid` → `users.id` (FK) | Operator, nullable            |
| `created_at` | `timestamptz`  | `default now()`                     |

`CHECK chk_status_history_transitions` mewajibkan haranya transisi legal
seperti tabel lifecycle di 2.3.

### 2.6 sensor_types
Katalog jenis sensor + rentang valid + presisi. Baris minimal yang di-seed:

| `code`          | Unit   | `valid_min` | `valid_max` | `precision` |
|-----------------|--------|-------------|-------------|-------------|
| `temp_air`      | °C     | -100        | 100         | 0.1         |
| `humidity`      | %      | 0           | 100         | 0.1         |
| `pressure`      | hPa    | 100         | 1100        | 0.01        |
| `wind_speed`    | m/s    | 0           | 100         | 0.1         |
| `wind_dir`      | °      | 0           | 360         | 1           |
| `rain_counter`  | tip    | 0           | 1_000_000_000 | 1         |
| `solar_rad`     | W/m²   | 0           | 2000        | 1           |

| Kolom          | Tipe            | Keterangan                        |
|----------------|-----------------|-----------------------------------|
| `id`           | `uuid` (PK)     |                                   |
| `code`         | `varchar(50)`   | `UNIQUE`                           |
| `name`         | `varchar(255)`  |                                   |
| `unit`         | `varchar(50)`   |                                   |
| `valid_min`/`valid_max`/`precision` | `numeric(20,6)` | nullable |

`CHECK chk_sensor_types_valid_range`: `valid_min <= valid_max` bila keduanya ada.

### 2.7 sensors
Unit sensor fisik (dipasang pada device, dirotasi antar lokasi).

| Kolom              | Tipe            | Keterangan                        |
|--------------------|-----------------|-----------------------------------|
| `id`               | `uuid` (PK)     |                                   |
| `serial_number`    | `varchar(100)`  | `UNIQUE`                           |
| `sensor_type_id`   | `uuid` → `sensor_types.id` (FK) | |
| `manufacturer`     | `varchar(255)`  | nullable                          |
| `model`            | `varchar(255)`  | nullable                          |
| `status`           | `varchar(30)`   |                                   |
| `created_at`/`updated_at`/`deleted_at` | `timestamptz` | soft delete |

Relasi `N:M` antara `devices` ↔ `sensors` dijembatani oleh `sensor_installations`.

### 2.8 sensor_installations
Riwayat pemasangan sensor pada device.

| Kolom          | Tipe            | Keterangan                            |
|----------------|-----------------|---------------------------------------|
| `id`           | `uuid` (PK)     |                                       |
| `sensor_id`    | `uuid` → `sensors.id` (FK, RESTRICT) | |
| `device_id`    | `uuid` → `devices.id` (FK, RESTRICT) | |
| `installed_at` | `timestamptz`   | NOT NULL                              |
| `removed_at`   | `timestamptz`   | NULL = masih aktif                    |
| `created_at`   | `timestamptz`   | `default now()`                       |

- **Partial unique index** `uq_sensor_installations_one_active (sensor_id)
  WHERE removed_at IS NULL` → sebuah sensor hanya boleh punya **satu** instalasi
  aktif pada satu waktu; memindahkan sensor = tutup baris lama (`removed_at`),
  buka baris baru di lokasi lain.
- `CHECK chk_sensor_installations_period`: `removed_at > installed_at`.

### 2.9 sensor_calibrations
Periode kalibrasi per sensor; hasil terkalibrasi hingga sekarang =
`corrected = raw * scale + offset`.

| Kolom            | Tipe            | Keterangan                          |
|------------------|-----------------|-------------------------------------|
| `id`             | `uuid` (PK)     |                                     |
| `sensor_id`      | `uuid` → `sensors.id` (FK, RESTRICT) | |
| `offset`         | `numeric`       | `default 0`                         |
| `scale`          | `numeric`       | `default 1`                         |
| `effective_from` | `timestamptz`   | NOT NULL                            |
| `effective_to`   | `timestamptz`   | NULL = berlaku terbuka sampai kini  |
| `created_at`     | `timestamptz`   |                                     |

`CHECK chk_sensor_calibrations_period`: `effective_to > effective_from`.

### 2.10 sensor_readings
Tabel time-series **narrow/long format** — satu baris per jenis sensor.
**Append-only**, didesain untuk volume ~184 juta baris/tahun
(50 device × 7 sensor × 1 menit).

| Kolom            | Tipe           | Keterangan                         |
|------------------|----------------|------------------------------------|
| `id`             | `bigint` identity | PK komposit dengan `device_time` |
| `device_id`      | `uuid` → `devices.id` (FK, RESTRICT) | |
| `sensor_id`      | `uuid` → `sensors.id` (FK, RESTRICT)  | |
| `device_time`    | `timestamptz`  | Stempel waktu sensor (utama), juga kolom partition |
| `server_time`    | `timestamptz`  | Waktu penerimaan server            |
| `seq`            | `bigint`       | Nomor urut dalam satu periode device |
| `raw_value`      | `numeric`      | Nilai mentah (tidak pernah diubah) |
| `corrected_value`| `numeric`      | Nilai terkalibrasi, nullable       |
| `quality_flag`   | `varchar(50)`  | `CHECK` — lihat daftar di bawah    |
| `reading_key`    | `varchar(255)` | Key deterministik, `NOT NULL`      |
| `created_at`     | `timestamptz`  | `default now()`                    |

**Partisi** — `PRIMARY KEY (id, device_time)`, `PARTITION BY RANGE (device_time)`,
partisi bulanan (`sensor_readings_p_YYYYMM`) + partisi `default` agar insert di
luar rentang yang sudah dibuat tidak pernah gagal. Karena PostgreSQL mewajibkan
kolom partisi ikut serta dalam PK/unique index, PK diformulasikan komposit
`(id, device_time)` dan unique `(reading_key, device_time)`.

Index: `idx_readings_sensor_time (sensor_id, device_time DESC)` (query agregasi
per sensor), `idx_readings_device_time (device_id, device_time DESC)`.

**reading_key deterministik:**

```
reading_key = device_id | epoch_micros(device_time) | sensor_id | seq
```

- `epoch_micros(device_time) = floor(extract(epoch FROM device_time) * 1_000_000)`
- Dihitung otomatis oleh trigger `trg_sensor_readings_reading_key`
  (BEFORE INSERT). Karena key hanya boleh memakai fungsi `IMMUTABLE` untuk
  kolom `GENERATED`, proyeksi ini dilakukan di trigger (boleh memakai fungsi
  `STABLE`), sehingga integritas tetap dijamin database.
- `UNIQUE (reading_key, device_time)` + `ON CONFLICT DO NOTHING` → **idempoten**:
  kiriman ulang dengan device/time/seq yang sama diabaikan.

**quality_flag** — `CHECK` membatasi:
`GOOD`, `OUT_OF_RANGE`, `SENSOR_ERROR`, `CLOCK_DRIFT`, `LATE`, `INVALID`,
`DUPLICATE`.

**Rumus curah hujan (rain):** nilai tersimpan adalah **counter** (jumlah tip;
1 tip = **0.2 mm**). Pada lapisan aplikasi, curah hujan per interval =
`(counter_baru - counter_lama) * 0.2`. Jika counter menurun (device reset),
curah hujan interval tersebut dianggap **0 (nol)** dan flag ditandai
`rain_reset`. Contoh validasi kualitas pada *dashboard* memakai skala:
`GOOD` = 78 (pembacaan normal), `OUT_OF_RANGE` = 79, `SENSOR_ERROR` = 80.

### 2.11 reading_aggregates
Agregat ringkas untuk query dashboard cepat. Unique `(sensor_id, interval,
bucket_start)` → proses `upsert` agregasi idempoten (jalan berulang tidak
membuat duplikat; nilai ditimpa).

| Kolom            | Tipe           | Keterangan                          |
|------------------|----------------|-------------------------------------|
| `id`             | `bigint` PK    |                                     |
| `sensor_id`      | `uuid` → `sensors.id` (FK, RESTRICT) | |
| `bucket_start`   | `timestamptz`  | Awal bucket (UTC)                   |
| `interval`       | `varchar(10)`  | `CHECK IN ('1m','1h','1d')`         |
| `min_value`/`max_value`/`avg_value`/`sum_value` | `numeric` | nullable |
| `sample_count`   | `int`          | `CHECK >= 0`                        |
| `quality_count`  | `int`          | `CHECK >= 0`                        |
| `created_at`     | `timestamptz`  |                                     |

Index: `(sensor_id, interval, bucket_start)` (unique),
`(sensor_id, interval, bucket_start DESC)` (query anti-mundur).

### 2.12 device_health (opsional, tetap disediakan)
Keadaan operasional *terkini*, dipisah dari identitas agar heartbeats tidak
membengkakkan `devices`.

| Kolom              | Tipe            | Keterangan                  |
|--------------------|-----------------|-----------------------------|
| `device_id`        | `uuid` PK → `devices.id` | 1:1                   |
| `battery_voltage`  | `numeric`       | nullable                   |
| `rssi`             | `numeric`       | nullable                   |
| `firmware_version` | `varchar(50)`   | nullable                   |
| `last_heartbeat_at`| `timestamptz`   | nullable                   |
| `uptime_seconds`   | `bigint`        | nullable                   |
| `updated_at`       | `timestamptz`   |                            |

---

## 3. Keputusan Desain (deviations & catatan)

1. **Basis `postgres:17` (tanpa TimescaleDB)** — partisi RANGE native.
   Akibatnya PK/unique index pada `sensor_readings` harus menyertakan kolom
   partisi: PK komposit `(id, device_time)`, unique `(reading_key, device_time)`,
   dan konflik target `ON CONFLICT (reading_key, device_time)`. Migration ke
   TimescaleDB di masa depan bisa dilakukan dengan memindahkan partisi, skema
   kolom tetap sama.
2. **Integritas `reading_key` dijaga trigger, bukan kolom generated** — kolom
   generated PostgreSQL hanya menerima ekspresi `IMMUTABLE`, sedangkan
   `extract(epoch ...)` bersifat `STABLE`. Trigger menghasilkan nilai yang sama
   secara deterministik; `UNIQUE` tetap menegakkan idempotensi.
3. **Deduplikasi murni di database** — aplikasi cukup memakai
   `INSERT ... ON CONFLICT (reading_key, device_time) DO NOTHING`.
4. **Enkripsi/secrecy** — `device_credentials` menyimpan `api_key` (untuk
   lookup) + `secret_hash`; tidak ada rahasia plaintext. `users.password_hash`
   hanya menyimpan hash.
5. **Pemeliharaan partisi** — partisi bulanan dibuat untuk 2026–2027. Operasi
  `retention` = `DETACH`/`DROP` partisi lama, bukan `DELETE` ribuan baris.
6. **Foreign keys memakai RESTRICT** untuk seluruh data historis; soft-delete
   device/sensor (`deleted_at`) melindungi kontinuitas pembacaan.
7. **Perhitungan delta** (mm hujan, increment, dsb.) dilakukan oleh penghitung
   pada frekuensi monitoring yang tepat, sehingga *counter reset* dapat
   dideteksi dengan andal.
8. **Autentikasi API** beralih dari `devices.api_key` (skema lama) ke
   `device_credentials` pada fase integrasi API; tabel `devices` tidak lagi
   membawa `api_key`.

---

## 4. Seeder

- `SensorTypeSeeder` — 7 tipe sensor inti (§2.6), idempoten
  (`ON CONFLICT (id) DO NOTHING`).
- `DemoDataSeeder` — 1 lokasi (Jakarta Hub), 1 device aktif `DEMO-001` dengan
  kredensial `dev_demo_weather_station_2024` (cocok dengan simulator/frontend
  demo), 7 sensor terpasang + kalibrasi identitas, 2 baris history status, dan
  1 baris `device_health`. Seluruhnya idempoten.

Jalankan (dari direktori `laravel/`):

```sh
php artisan migrate --seed
```

---

## 5. Growth Estimation

| Komponen                 | Estimasi                                    |
|--------------------------|---------------------------------------------|
| Perangkat aktif          | 50 device                                    |
| Sensor/device            | 7 sensor                                     |
| Frekuensi                | 1 menit/device → 60 pembacaan/sensor/jam    |
| **Baris sensor_readings**| **0,5 juta/hari ≈ 184 juta/tahun**           |
| Volume row ~185 B        | ~34 GB/tahun partisi (raw + index)           |
| Agregat 1m               | 50×7×1440 = 504 ribu baris/hari (~184 jt/thn)|
| Agregat 1h / 1d          | 8.400 / 350 baris/hari                       |

Index dipilih hanya untuk pola query nyata (agregat per sensor/device DESC);
tidak ada index buta. Partisi `default` mencegah kegagalan insert tak terduga di
luar rentang partisi yang dibuat.