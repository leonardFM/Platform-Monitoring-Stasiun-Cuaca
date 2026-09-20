<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * sensor_readings adalah tabel time-series ber-volume tinggi
     * (~184 juta rows/tahun). Dipartisi native PostgreSQL
     * (RANGE per bulan pada device_time).
     */
    public function up(): void
    {
        if (Schema::hasTable('sensor_readings')) {
            return;
        }

        DB::unprepared(
            <<<'SQL'
            CREATE TABLE sensor_readings (
                id              BIGINT GENERATED ALWAYS AS IDENTITY,
                device_id       UUID        NOT NULL,
                sensor_id       UUID        NOT NULL,
                device_time     TIMESTAMPTZ NOT NULL,
                server_time     TIMESTAMPTZ NOT NULL,
                seq             BIGINT      NOT NULL,
                raw_value       DECIMAL     NOT NULL,
                corrected_value DECIMAL,
                quality_flag    VARCHAR(50) NOT NULL,
                reading_key     VARCHAR(255)
                                NOT NULL,
                created_at      TIMESTAMPTZ NOT NULL DEFAULT now(),
                PRIMARY KEY (id, device_time),
                CONSTRAINT uq_reading_key_device_time UNIQUE (reading_key, device_time),
                CONSTRAINT fk_readings_device
                    FOREIGN KEY (device_id) REFERENCES devices(id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT fk_readings_sensor
                    FOREIGN KEY (sensor_id) REFERENCES sensors(id)
                    ON UPDATE RESTRICT ON DELETE RESTRICT,
                CONSTRAINT chk_reading_quality_flag CHECK (
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
                )
            ) PARTITION BY RANGE (device_time);

            CREATE INDEX idx_readings_sensor_time
                ON sensor_readings (sensor_id, device_time DESC);
            CREATE INDEX idx_readings_device_time
                ON sensor_readings (device_id, device_time DESC);

            CREATE FUNCTION sensor_readings_set_reading_key() RETURNS trigger
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

            CREATE TRIGGER trg_sensor_readings_reading_key
                BEFORE INSERT OR UPDATE ON sensor_readings
                FOR EACH ROW EXECUTE FUNCTION sensor_readings_set_reading_key();
            SQL
        );

        // Default partition: catches device_time di luar rentang bulan
        // yang sudah dibuat, supaya insert tidak pernah error "no partition".
        DB::unprepared(
            'CREATE TABLE IF NOT EXISTS sensor_readings_default
             PARTITION OF sensor_readings DEFAULT'
        );

        // Partisi bulanan untuk tahun berjalan + satu tahun ke depan.
        DB::unprepared(
            <<<'SQL'
            DO $$
            DECLARE
                y     int;
                m     int;
                start_d date;
                end_d   date;
                pname text;
            BEGIN
                FOR y IN 2026..2027 LOOP
                    FOR m IN 1..12 LOOP
                        start_d := make_date(y, m, 1);
                        end_d   := (start_d + interval '1 month')::date;
                        pname   := 'sensor_readings_p_' || to_char(start_d, 'YYYYMM');
                        IF NOT EXISTS (
                            SELECT 1 FROM pg_class c
                            JOIN pg_namespace n ON n.oid = c.relnamespace
                            WHERE n.nspname = 'public' AND c.relname = pname
                        ) THEN
                            EXECUTE format(
                                'CREATE TABLE %I PARTITION OF sensor_readings
                                 FOR VALUES FROM (%L) TO (%L)',
                                pname, start_d, end_d
                            );
                        END IF;
                    END LOOP;
                END LOOP;
            END $$;
            SQL
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_readings');
    }
};