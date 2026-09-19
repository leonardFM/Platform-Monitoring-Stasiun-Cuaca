<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('serial_number', 100)->unique();
            $table->uuid('sensor_type_id');
            $table->foreign('sensor_type_id')->references('id')->on('sensor_types')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->string('manufacturer', 255)->nullable();
            $table->string('model', 255)->nullable();
            $table->string('status', 30);
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
            $table->timestampTz('deleted_at')->nullable();

            $table->index('sensor_type_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sensors');
    }
};