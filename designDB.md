# Database Design Document

## Overview
This document describes the database schema for the IoT Weather Station system. The database uses PostgreSQL with TimescaleDB extension for time-series data (sensor_readings, reading_aggregates).

---

## Tables

### 1. users
**Description**: User accounts for system access and device status change tracking.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| name | VARCHAR(255) | NOT NULL | - |
| email | VARCHAR(255) | NOT NULL, UNIQUE | - |
| password_hash | TEXT | NOT NULL | - |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| deleted_at | TIMESTAMPTZ | NULLABLE | NULL |

**Indexes**: None (PK on id)

**Foreign Keys**: None

---

### 2. locations
**Description**: Geographic locations where devices are deployed.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| name | VARCHAR(255) | NOT NULL | - |
| latitude | DECIMAL(9,6) | NOT NULL, CHECK (-90 to 90) | - |
| longitude | DECIMAL(9,6) | NOT NULL, CHECK (-180 to 180) | - |
| altitude | DECIMAL(10,2) | NULLABLE | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**: None (PK on id)

**Check Constraints**:
- `chk_locations_latitude`: latitude BETWEEN -90 AND 90
- `chk_locations_longitude`: longitude BETWEEN -180 AND 180

**Foreign Keys**: None

**Relationships**:
- One-to-Many: locations → devices (location_id)

---

### 3. devices
**Description**: IoT devices deployed at locations.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| device_code | VARCHAR(100) | NOT NULL, UNIQUE | - |
| name | VARCHAR(255) | NOT NULL | - |
| location_id | UUID | NOT NULL, FK → locations.id | - |
| status | VARCHAR(30) | NOT NULL, CHECK (provisioned\|active\|maintenance\|decommissioned) | - |
| firmware_version | VARCHAR(50) | NULLABLE | NULL |
| last_seen_at | TIMESTAMPTZ | NULLABLE | NULL |
| last_device_time | TIMESTAMPTZ | NULLABLE | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| deleted_at | TIMESTAMPTZ | NULLABLE | NULL |

**Indexes**:
- `idx_devices_location_id` on (location_id)
- `idx_devices_status` on (status)
- `idx_devices_last_seen_at` on (last_seen_at)
- `idx_devices_status_last_seen_at` on (status, last_seen_at)

**Check Constraints**:
- `chk_devices_status`: status IN ('provisioned', 'active', 'maintenance', 'decommissioned')

**Foreign Keys**:
- `devices_location_id_foreign`: location_id → locations.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: devices → locations (location_id)
- One-to-Many: devices → device_credentials (device_id)
- One-to-One: devices → device_health (device_id)
- One-to-Many: devices → device_status_history (device_id)
- One-to-Many: devices → sensor_installations (device_id)
- One-to-Many: devices → sensor_readings (device_id)

---

### 4. device_credentials
**Description**: API authentication credentials for devices.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| device_id | UUID | NOT NULL, FK → devices.id | - |
| api_key | VARCHAR(255) | NOT NULL, UNIQUE | - |
| secret_hash | TEXT | NOT NULL | - |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| last_used_at | TIMESTAMPTZ | NULLABLE | NULL |
| revoked_at | TIMESTAMPTZ | NULLABLE | NULL |

**Indexes**:
- `idx_device_credentials_device_id` on (device_id)
- `idx_device_credentials_device_revoked` on (device_id, revoked_at)

**Foreign Keys**:
- `device_credentials_device_id_foreign`: device_id → devices.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: device_credentials → devices (device_id)

---

### 5. device_status_history
**Description**: Audit log of device status transitions.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| device_id | UUID | NOT NULL, FK → devices.id | - |
| from_status | VARCHAR(30) | NULLABLE, CHECK (provisioned\|active\|maintenance\|decommissioned) | NULL |
| to_status | VARCHAR(30) | NOT NULL, CHECK (provisioned\|active\|maintenance\|decommissioned) | - |
| reason | TEXT | NULLABLE | NULL |
| changed_by | UUID | NULLABLE, FK → users.id | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**:
- `idx_device_status_history_device_id` on (device_id)
- `idx_device_status_history_device_created` on (device_id, created_at)
- `idx_device_status_history_changed_by` on (changed_by)

**Check Constraints**:
- `chk_status_history_values`: from_status IS NULL OR from_status IN ('provisioned', 'active', 'maintenance', 'decommissioned')
- `chk_status_history_transitions`: Valid transitions only:
  - NULL → 'provisioned'
  - 'provisioned' → 'active'
  - 'active' → 'maintenance'
  - 'maintenance' → 'active'
  - 'active' → 'decommissioned'
  - 'maintenance' → 'decommissioned'

**Foreign Keys**:
- `device_status_history_device_id_foreign`: device_id → devices.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)
- `device_status_history_changed_by_foreign`: changed_by → users.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: device_status_history → devices (device_id)
- Many-to-One: device_status_history → users (changed_by)

---

### 6. sensor_types
**Description**: Reference table for sensor types and their measurement units.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| code | VARCHAR(50) | NOT NULL, UNIQUE | - |
| name | VARCHAR(255) | NOT NULL | - |
| unit | VARCHAR(50) | NOT NULL | - |
| valid_min | DECIMAL(20,6) | NULLABLE | NULL |
| valid_max | DECIMAL(20,6) | NULLABLE | NULL |
| precision | DECIMAL(20,6) | NULLABLE | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**: None (PK on id)

**Check Constraints**:
- `chk_sensor_types_valid_range`: valid_min IS NULL OR valid_max IS NULL OR valid_min <= valid_max

**Foreign Keys**: None

**Relationships**:
- One-to-Many: sensor_types → sensors (sensor_type_id)

---

### 7. sensors
**Description**: Physical sensor hardware inventory.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| serial_number | VARCHAR(100) | NOT NULL, UNIQUE | - |
| sensor_type_id | UUID | NOT NULL, FK → sensor_types.id | - |
| manufacturer | VARCHAR(255) | NULLABLE | NULL |
| model | VARCHAR(255) | NULLABLE | NULL |
| status | VARCHAR(30) | NOT NULL | - |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |
| deleted_at | TIMESTAMPTZ | NULLABLE | NULL |

**Indexes**:
- `idx_sensors_sensor_type_id` on (sensor_type_id)

**Foreign Keys**:
- `sensors_sensor_type_id_foreign`: sensor_type_id → sensor_types.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: sensors → sensor_types (sensor_type_id)
- One-to-Many: sensors → sensor_installations (sensor_id)
- One-to-Many: sensors → sensor_calibrations (sensor_id)
- One-to-Many: sensors → sensor_readings (sensor_id)
- One-to-Many: sensors → reading_aggregates (sensor_id)

---

### 8. sensor_installations
**Description**: Tracks which sensors are installed on which devices and when.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| sensor_id | UUID | NOT NULL, FK → sensors.id | - |
| device_id | UUID | NOT NULL, FK → devices.id | - |
| installed_at | TIMESTAMPTZ | NOT NULL | - |
| removed_at | TIMESTAMPTZ | NULLABLE | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**:
- `idx_sensor_installations_sensor_installed` on (sensor_id, installed_at)
- `idx_sensor_installations_device_installed` on (device_id, installed_at)
- `uq_sensor_installations_one_active`: UNIQUE partial index on (sensor_id) WHERE removed_at IS NULL

**Check Constraints**:
- `chk_sensor_installations_period`: removed_at IS NULL OR removed_at > installed_at

**Foreign Keys**:
- `sensor_installations_sensor_id_foreign`: sensor_id → sensors.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)
- `sensor_installations_device_id_foreign`: device_id → devices.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: sensor_installations → sensors (sensor_id)
- Many-to-One: sensor_installations → devices (device_id)

---

### 9. sensor_calibrations
**Description**: Calibration parameters for sensors with effective date ranges.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | UUID | PRIMARY KEY | gen_random_uuid() |
| sensor_id | UUID | NOT NULL, FK → sensors.id | - |
| offset | DECIMAL | NOT NULL | 0 |
| scale | DECIMAL | NOT NULL | 1 |
| effective_from | TIMESTAMPTZ | NOT NULL | - |
| effective_to | TIMESTAMPTZ | NULLABLE | NULL |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**:
- `idx_sensor_calibrations_sensor_effective` on (sensor_id, effective_from)

**Check Constraints**:
- `chk_sensor_calibrations_period`: effective_to IS NULL OR effective_to > effective_from

**Foreign Keys**:
- `sensor_calibrations_sensor_id_foreign`: sensor_id → sensors.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- Many-to-One: sensor_calibrations → sensors (sensor_id)

---

### 10. sensor_readings (TimescaleDB Hypertable)
**Description**: High-volume time-series sensor readings (~184M rows/year). Partitioned by device_time with 7-day chunks.

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | BIGINT | NOT NULL, GENERATED ALWAYS AS IDENTITY | - |
| device_id | UUID | NOT NULL, FK → devices.id | - |
| sensor_id | UUID | NOT NULL, FK → sensors.id | - |
| device_time | TIMESTAMPTZ | NOT NULL | - |
| server_time | TIMESTAMPTZ | NOT NULL | - |
| seq | BIGINT | NOT NULL | - |
| raw_value | DECIMAL | NOT NULL | - |
| corrected_value | DECIMAL | NULLABLE | NULL |
| quality_flag | VARCHAR(50) | NOT NULL, CHECK (GOOD\|OUT_OF_RANGE\|SENSOR_ERROR\|CLOCK_DRIFT\|LATE\|INVALID\|DUPLICATE\|RAIN_INITIAL\|RAIN_RESET) | - |
| reading_key | VARCHAR(255) | NOT NULL | Auto-generated via trigger |
| created_at | TIMESTAMPTZ | NOT NULL | now() |

**Primary Key**: (id, device_time) - composite for hypertable

**Indexes**:
- `idx_readings_sensor_time` on (sensor_id, device_time DESC)
- `idx_readings_device_time` on (device_id, device_time DESC)

**Unique Constraints**:
- `uq_reading_key_device_time`: UNIQUE (reading_key, device_time)

**Check Constraints**:
- `chk_reading_quality_flag`: quality_flag IN ('GOOD', 'OUT_OF_RANGE', 'SENSOR_ERROR', 'CLOCK_DRIFT', 'LATE', 'INVALID', 'DUPLICATE', 'RAIN_INITIAL', 'RAIN_RESET')

**Foreign Keys**:
- `fk_readings_device`: device_id → devices.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)
- `fk_readings_sensor`: sensor_id → sensors.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Partitioning**: RANGE partition by device_time (monthly partitions via native PostgreSQL partitioning, then converted to TimescaleDB hypertable with 7-day chunks)

**Trigger**: `trg_sensor_readings_reading_key` (BEFORE INSERT OR UPDATE) - auto-generates reading_key if null:
```
reading_key = device_id || '|' || floor(epoch(device_time) * 1000000) || '|' || sensor_id || '|' || seq
```

**Relationships**:
- Many-to-One: sensor_readings → devices (device_id)
- Many-to-One: sensor_readings → sensors (sensor_id)

---

### 11. reading_aggregates (TimescaleDB Hypertable)
**Description**: Pre-computed aggregates for sensor readings (1m, 1h, 1d intervals).

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| id | BIGINT | NOT NULL, PRIMARY KEY (with bucket_start) | - |
| sensor_id | UUID | NOT NULL, FK → sensors.id | - |
| bucket_start | TIMESTAMPTZ | NOT NULL | - |
| interval | VARCHAR(10) | NOT NULL, CHECK ('1m'\|'1h'\|'1d') | - |
| min_value | DECIMAL | NULLABLE | NULL |
| max_value | DECIMAL | NULLABLE | NULL |
| avg_value | DECIMAL | NULLABLE | NULL |
| sum_value | DECIMAL(16,4) | NULLABLE | NULL |
| sample_count | INTEGER | NOT NULL | - |
| quality_count | INTEGER | NOT NULL | - |
| created_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Primary Key**: (id, bucket_start) - composite for hypertable

**Unique Constraints**:
- `reading_aggregates_sensor_id_interval_bucket_start_key`: UNIQUE (sensor_id, interval, bucket_start)

**Indexes**:
- `idx_aggregates_sensor_interval_bucket_desc` on (sensor_id, interval, bucket_start DESC)

**Check Constraints**:
- `chk_reading_aggregates_interval`: interval IN ('1m', '1h', '1d')
- `chk_reading_aggregates_nulls`: sample_count >= 0 AND quality_count >= 0

**Foreign Keys**:
- `reading_aggregates_sensor_id_foreign`: sensor_id → sensors.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Partitioning**: TimescaleDB hypertable on bucket_start with 1-day chunks

**Relationships**:
- Many-to-One: reading_aggregates → sensors (sensor_id)

---

### 12. device_health
**Description**: Operational health metrics for devices (separate from device identity for performance).

| Column | Data Type | Constraints | Default |
|--------|-----------|-------------|---------|
| device_id | UUID | PRIMARY KEY, FK → devices.id | - |
| battery_voltage | DECIMAL | NULLABLE | NULL |
| rssi | DECIMAL | NULLABLE | NULL |
| firmware_version | VARCHAR(50) | NULLABLE | NULL |
| last_heartbeat_at | TIMESTAMPTZ | NULLABLE | NULL |
| uptime_seconds | BIGINT | NULLABLE | NULL |
| last_seq | BIGINT | NULLABLE | NULL |
| updated_at | TIMESTAMPTZ | NOT NULL | CURRENT_TIMESTAMP |

**Indexes**: None (PK on device_id)

**Foreign Keys**:
- `device_health_device_id_foreign`: device_id → devices.id (ON UPDATE RESTRICT, ON DELETE RESTRICT)

**Relationships**:
- One-to-One: device_health → devices (device_id)

---

## Relationship Diagram (Text)

```
users 1─────∞ device_status_history (changed_by)
locations 1─────∞ devices (location_id)
devices 1─────∞ device_credentials (device_id)
devices 1─────1 device_health (device_id)
devices 1─────∞ device_status_history (device_id)
devices 1─────∞ sensor_installations (device_id)
devices 1─────∞ sensor_readings (device_id)
sensor_types 1─────∞ sensors (sensor_type_id)
sensors 1─────∞ sensor_installations (sensor_id)
sensors 1─────∞ sensor_calibrations (sensor_id)
sensors 1─────∞ sensor_readings (sensor_id)
sensors 1─────∞ reading_aggregates (sensor_id)
```

---

## Key Design Decisions

1. **UUID Primary Keys**: All entity tables use UUID for distributed-friendly IDs
2. **Soft Deletes**: users, devices, sensors use deleted_at for logical deletion
3. **TimescaleDB Hypertables**: sensor_readings and reading_aggregates use TimescaleDB for time-series performance
4. **Composite PKs**: Time-series tables use (id, time_column) PK for hypertable compatibility
5. **Partial Unique Index**: sensor_installations enforces one active installation per sensor
6. **State Machine**: device_status_history enforces valid status transitions via CHECK constraint
7. **Separation of Concerns**: device_health separated from devices to avoid row bloat from frequent heartbeat updates
8. **Reading Key**: Auto-generated composite key (device|time|sensor|seq) for idempotent inserts
9. **Quality Flags**: Comprehensive quality_flag enum for data quality tracking
10. **Native Partitioning → Hypertable**: sensor_readings migrated from native PostgreSQL monthly partitioning to TimescaleDB 7-day chunks