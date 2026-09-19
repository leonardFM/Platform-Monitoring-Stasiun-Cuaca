<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_installations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sensor_id');
            $table->foreign('sensor_id')->references('id')->on('sensors')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->uuid('device_id');
            $table->foreign('device_id')->references('id')->on('devices')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->timestampTz('installed_at');
            $table->timestampTz('removed_at')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['sensor_id', 'installed_at']);
            $table->index(['device_id', 'installed_at']);
        });

        // A sensor may only have a single *active* installation
        // (removed_at IS NULL) at any point in time.
        DB::unprepared(
            'CREATE UNIQUE INDEX uq_sensor_installations_one_active
                ON sensor_installations (sensor_id)
                WHERE removed_at IS NULL'
        );
        DB::unprepared(
            'ALTER TABLE sensor_installations
                ADD CONSTRAINT chk_sensor_installations_period
                CHECK (removed_at IS NULL OR removed_at > installed_at)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_installations');
    }
};