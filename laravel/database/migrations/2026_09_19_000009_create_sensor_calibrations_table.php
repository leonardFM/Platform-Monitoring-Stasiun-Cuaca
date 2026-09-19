<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sensor_calibrations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('sensor_id');
            $table->foreign('sensor_id')->references('id')->on('sensors')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->decimal('offset')->default(0);
            $table->decimal('scale')->default(1);
            $table->timestampTz('effective_from');
            $table->timestampTz('effective_to')->nullable();
            $table->timestampTz('created_at')->useCurrent();

            $table->index(['sensor_id', 'effective_from']);
        });

        DB::unprepared(
            'ALTER TABLE sensor_calibrations
                ADD CONSTRAINT chk_sensor_calibrations_period
                CHECK (effective_to IS NULL OR effective_to > effective_from)'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('sensor_calibrations');
    }
};