<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('reading_aggregates')) {
            return;
        }

        if (DB::selectOne("SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_name = 'reading_aggregates'")) {
            return;
        }

        DB::transaction(function (): void {
            DB::unprepared('ALTER TABLE reading_aggregates DROP CONSTRAINT IF EXISTS reading_aggregates_pkey');
            DB::unprepared('ALTER TABLE reading_aggregates ADD PRIMARY KEY (id, bucket_start)');

            DB::unprepared(
                "SELECT create_hypertable('reading_aggregates', 'bucket_start', chunk_time_interval => INTERVAL '1 day', if_not_exists => TRUE, migrate_data => TRUE)"
            );

            DB::unprepared('DROP INDEX IF EXISTS idx_aggregates_sensor_interval_bucket_desc');
            DB::unprepared(
                'CREATE INDEX idx_aggregates_sensor_interval_bucket_desc
                 ON reading_aggregates (sensor_id, "interval", bucket_start DESC)'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('reading_aggregates')) {
            if (! DB::selectOne("SELECT 1 FROM timescaledb_information.hypertables WHERE hypertable_name = 'reading_aggregates'")) {
                return;
            }
            DB::unprepared('SELECT drop_hypertable(\'reading_aggregates\', if_exists => TRUE, cascade => TRUE)');
            DB::unprepared('ALTER TABLE reading_aggregates DROP CONSTRAINT IF EXISTS reading_aggregates_pkey');
            DB::unprepared('ALTER TABLE reading_aggregates ADD PRIMARY KEY (id)');
        }
    }
};