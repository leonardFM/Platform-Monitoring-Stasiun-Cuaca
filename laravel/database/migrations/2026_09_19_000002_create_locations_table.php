<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name', 255);
            $table->decimal('latitude', 9, 6);
            $table->decimal('longitude', 9, 6);
            $table->decimal('altitude', 10, 2)->nullable();
            $table->timestampTz('created_at')->useCurrent();
            $table->timestampTz('updated_at')->useCurrent();
        });

        DB::unprepared(
            'ALTER TABLE locations
                ADD CONSTRAINT chk_locations_latitude
                CHECK (latitude BETWEEN -90 AND 90)'
        );
        DB::unprepared(
            'ALTER TABLE locations
                ADD CONSTRAINT chk_locations_longitude
                CHECK (longitude BETWEEN -180 AND 180)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('locations');
    }
};