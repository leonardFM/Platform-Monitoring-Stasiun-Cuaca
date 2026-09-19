<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_types', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('code', 50)->unique();
            $table->string('name', 255);
            $table->string('unit', 50);
            $table->decimal('valid_min', 20, 6)->nullable();
            $table->decimal('valid_max', 20, 6)->nullable();
            $table->decimal('precision', 20, 6)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(
            'ALTER TABLE sensor_types
                ADD CONSTRAINT chk_sensor_types_valid_range
                CHECK (valid_min IS NULL OR valid_max IS NULL OR valid_min <= valid_max)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_types');
    }
};