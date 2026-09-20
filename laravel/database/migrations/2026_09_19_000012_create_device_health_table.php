<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Keadaan operasional device saat ini, dipisah dari identity/lifecycle
     * (devices) supaya metadata heartbeat tidak memperbesar baris device.
     */
    public function up(): void
    {
        Schema::create('device_health', function (Blueprint $table) {
            $table->uuid('device_id')->primary();
            $table->foreign('device_id')->references('id')->on('devices')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->decimal('battery_voltage')->nullable();
            $table->decimal('rssi')->nullable();
            $table->string('firmware_version', 50)->nullable();
            $table->timestampTz('last_heartbeat_at')->nullable();
            $table->bigInteger('uptime_seconds')->nullable();
            $table->bigInteger('last_seq')->nullable();
            $table->timestampTz('updated_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('device_health');
    }
};