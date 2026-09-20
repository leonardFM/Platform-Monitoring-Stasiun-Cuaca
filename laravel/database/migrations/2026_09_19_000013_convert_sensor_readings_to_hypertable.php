<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('sensor_readings')) {
            return;
        }

        if (DB::selectOne("SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_name = 'sensor_readings'")) {
            return;
        }

        DB::transaction(function (): void {
            // 1. Create new table WITHOUT conflicting constraint names
            DB::unprepared(
                <<<'SQL'
                CREATE TABLE sensor_readings_hyper (
                    id              BIGINT GENERATED ALWAYS AS IDENTITY,
                    device_id       UUID        NOT NULL,
                    sensor_id       UUID        NOT NULL,
                    device_time     TIMESTAMPTZ NOT NULL,
                    server_time     TIMESTAMPTZ NOT NULL,
                    seq             BIGINT      NOT NULL,
                    raw_value       DECIMAL     NOT NULL,
                    corrected_value DECIMAL,
                    quality_flag    VARCHAR(50) NOT NULL,
                    reading_key     VARCHAR(255) NOT NULL,
                    created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                    PRIMARY KEY (id, device_time)
                );
                SQL
            );

            DB::unprepared(
                "SELECT create_hypertable('sensor_readings_hyper', 'device_time', chunk_time_interval => INTERVAL '7 days', if_not_exists => TRUE, migrate_data => FALSE)"
            );

            // 2. Copy data from old partitioned table (OVERRIDING SYSTEM VALUE for GENERATED ALWAYS id)
            DB::unprepared(
                "INSERT INTO sensor_readings_hyper (id, device_id, sensor_id, device_time, server_time, seq, raw_value, corrected_value, quality_flag, reading_key, created_at)
                 OVERRIDING SYSTEM VALUE
                 SELECT id, device_id, sensor_id, device_time, server_time, seq, raw_value, corrected_value, quality_flag, reading_key, created_at
                 FROM sensor_readings
                 ON CONFLICT (id, device_time) DO NOTHING"
            );

            // 3. Drop old partitioned table (and all its partitions + constraints)
            DB::unprepared('DROP TABLE sensor_readings CASCADE');

            // 4. Rename new hypertable
            DB::unprepared('ALTER TABLE sensor_readings_hyper RENAME TO sensor_readings');

            // 5. Add constraints, FKs, indexes, trigger
            DB::unprepared(
                'ALTER TABLE sensor_readings
                 ADD CONSTRAINT uq_reading_key_device_time UNIQUE (reading_key, device_time)'
            );
            DB::unprepared(
                'ALTER TABLE sensor_readings
                 ADD CONSTRAINT fk_readings_device FOREIGN KEY (device_id) REFERENCES devices(id) ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            DB::unprepared(
                'ALTER TABLE sensor_readings
                 ADD CONSTRAINT fk_readings_sensor FOREIGN KEY (sensor_id) REFERENCES sensors(id) ON UPDATE RESTRICT ON DELETE RESTRICT'
            );
            DB::unprepared(
                <<<'SQL'
                ALTER TABLE sensor_readings
                ADD CONSTRAINT chk_reading_quality_flag CHECK (
                    quality_flag IN (
                        'GOOD',
                        'OUT_OF_RANGE',
                        'SENSOR_ERROR',
                        'CLOCK_DRIFT',
                        'LATE',
                        'INVALID',
                        'DUPLICATE',
                        'RAIN_INITIAL',
                        'RAIN_RESET'
                    )
                );
                SQL
            );

            DB::unprepared('CREATE INDEX idx_readings_sensor_time ON sensor_readings (sensor_id, device_time DESC)');
            DB::unprepared('CREATE INDEX idx_readings_device_time ON sensor_readings (device_id, device_time DESC)');

            DB::unprepared(
                <<<'SQL'
                CREATE OR REPLACE FUNCTION sensor_readings_set_reading_key() RETURNS trigger
                LANGUAGE plpgsql AS $$
                BEGIN
                    IF NEW.reading_key IS NULL THEN
                        NEW.reading_key := NEW.device_id::text || '|' ||
                            (floor(extract(epoch FROM NEW.device_time) * 1000000))::bigint::text || '|' ||
                            NEW.sensor_id::text || '|' ||
                            NEW.seq::text;
                    END IF;
                    RETURN NEW;
                END;
                $$;
                SQL
            );

            DB::unprepared('DROP TRIGGER IF EXISTS trg_sensor_readings_reading_key ON sensor_readings');
            DB::unprepared(
                'CREATE TRIGGER trg_sensor_readings_reading_key
                 BEFORE INSERT OR UPDATE ON sensor_readings
                 FOR EACH ROW EXECUTE FUNCTION sensor_readings_set_reading_key()'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('sensor_readings')) {
            if (! DB::selectOne("SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_name = 'sensor_readings'")) {
                return;
            }
            DB::unprepared('SELECT drop_hypertable(\'sensor_readings\', if_exists => TRUE, cascade => TRUE)');
        }
    }
};