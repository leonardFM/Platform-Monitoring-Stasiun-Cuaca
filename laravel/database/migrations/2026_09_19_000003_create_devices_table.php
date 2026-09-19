<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('devices', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('device_code', 100)->unique();
            $table->string('name', 255);
            $table->uuid('location_id');
            $table->foreign('location_id')->references('id')->on('locations')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->string('status', 30);
            $table->string('firmware_version', 50)->nullable();
            $table->timestampTz('last_seen_at')->nullable();
            $table->timestampTz('last_device_time')->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();

            $table->index('location_id');
            $table->index('status');
            $table->index('last_seen_at');
            $table->index(['status', 'last_seen_at']);
        });

        DB::unprepared(
            "ALTER TABLE devices
                ADD CONSTRAINT chk_devices_status
                CHECK (status IN ('provisioned', 'active', 'maintenance', 'decommissioned'))"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('devices');
    }
};