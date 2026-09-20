<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        // Drop existing unique index on api_key
        DB::unprepared('DROP INDEX IF EXISTS device_credentials_api_key_unique ON device_credentials');
        
        // Add composite unique index on (device_id, api_key)
        DB::unprepared('CREATE UNIQUE INDEX device_credentials_device_id_api_key_unique ON device_credentials (device_id, api_key)');
    }

    public function down(): void
    {
        // Drop composite index
        DB::unprepared('DROP INDEX IF EXISTS device_credentials_device_id_api_key_unique ON device_credentials');
        
        // Restore unique index on api_key
        DB::unprepared('CREATE UNIQUE INDEX device_credentials_api_key_unique ON device_credentials (api_key)');
    }
};
