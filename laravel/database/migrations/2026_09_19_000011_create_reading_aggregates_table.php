<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('reading_aggregates', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->uuid('sensor_id');
            $table->foreign('sensor_id')->references('id')->on('sensors')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->timestampTz('bucket_start');
            $table->string('interval', 10);
            $table->decimal('min_value')->nullable();
            $table->decimal('max_value')->nullable();
            $table->decimal('avg_value')->nullable();
            $table->decimal('sum_value')->nullable();
            $table->integer('sample_count');
            $table->integer('quality_count');
            $table->timestampTz('created_at')->useCurrent();

            $table->unique(['sensor_id', 'interval', 'bucket_start']);
        });

        DB::unprepared(
            'CREATE INDEX idx_aggregates_sensor_interval_bucket_desc
             ON reading_aggregates (sensor_id, "interval", bucket_start DESC)'
        );
        DB::unprepared(
            "ALTER TABLE reading_aggregates
                ADD CONSTRAINT chk_reading_aggregates_interval
                CHECK (\"interval\" IN ('1m', '1h', '1d'))"
        );
        DB::unprepared(
            'ALTER TABLE reading_aggregates
                ADD CONSTRAINT chk_reading_aggregates_nulls
                CHECK (sample_count >= 0 AND quality_count >= 0)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('reading_aggregates');
    }
};