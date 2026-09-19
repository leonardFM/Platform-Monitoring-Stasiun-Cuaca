<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('device_status_history', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->uuid('device_id');
            $table->foreign('device_id')->references('id')->on('devices')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->string('from_status', 30)->nullable();
            $table->string('to_status', 30);
            $table->text('reason')->nullable();
            $table->uuid('changed_by')->nullable();
            $table->foreign('changed_by')->references('id')->on('users')
                ->onUpdate('restrict')->onDelete('restrict');
            $table->timestampTz('created_at')->useCurrent();

            $table->index('device_id');
            $table->index(['device_id', 'created_at']);
            $table->index('changed_by');
        });

        DB::unprepared(
            'ALTER TABLE device_status_history
                ADD CONSTRAINT chk_status_history_values
                CHECK (
                    from_status IN (\'provisioned\', \'active\', \'maintenance\', \'decommissioned\') IS NULL
                    OR from_status IN (\'provisioned\', \'active\', \'maintenance\', \'decommissioned\')
                )'
        );
        DB::unprepared(
            'ALTER TABLE device_status_history
                ADD CONSTRAINT chk_status_history_transitions
                CHECK (
                    (from_status IS NULL AND to_status = \'provisioned\')
                    OR (from_status = \'provisioned\' AND to_status = \'active\')
                    OR (from_status = \'active\' AND to_status = \'maintenance\')
                    OR (from_status = \'maintenance\' AND to_status = \'active\')
                    OR (from_status = \'active\' AND to_status = \'decommissioned\')
                    OR (from_status = \'maintenance\' AND to_status = \'decommissioned\')
                )'
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('device_status_history');
    }
};