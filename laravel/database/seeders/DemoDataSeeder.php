<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data demo: 1 lokasi, 1 device aktif dengan 7 sensor yang terpasang
 * dan terkalibrasi, plus credentials yang cocok dengan kunci demo
 * frontend/simulator (dev_demo_weather_station_2024).
 *
 * Idempoten: semua INSERT memakai ON CONFLICT DO NOTHING.
 */
class DemoDataSeeder extends Seeder
{
    private const LOC_ID    = '11111111-1111-4111-8111-111111111111';
    private const DEV_ID    = '22222222-2222-4222-8222-222222222222';
    private const CRED_ID   = '33333333-3333-4333-8333-333333333333';
    private const HIST_ID1  = '44444444-4444-4444-8444-444444444441';
    private const HIST_ID2  = '44444444-4444-4444-8444-444444444442';

    private function sensorId(int $n): string
    {
        return sprintf('a0000000-0000-4000-8000-%012d', $n);
    }

    public function run(): void
    {
        $apiKey = 'dev_demo_weather_station_2024';

        DB::statement(
            'INSERT INTO locations (id, name, latitude, longitude, altitude)
             VALUES (?, ?, ?, ?, ?)
             ON CONFLICT (id) DO NOTHING',
            [self::LOC_ID, 'Jakarta Hub', -6.200000, 106.816667, 8.00]
        );

        DB::statement(
            "INSERT INTO devices (id, device_code, name, location_id, status, firmware_version)
             VALUES (?, ?, ?, ?, 'active', ?)
             ON CONFLICT (id) DO NOTHING",
            [self::DEV_ID, 'DEMO-001', 'Demo Station Alpha', self::LOC_ID, '1.0.0']
        );

        DB::statement(
            'INSERT INTO device_credentials (id, device_id, api_key, secret_hash)
             VALUES (?, ?, ?, ?)
             ON CONFLICT (id) DO NOTHING',
            [self::CRED_ID, self::DEV_ID, $apiKey, hash('sha256', $apiKey)]
        );

        // Riwayat status awal (valid sesuai CHECK transition).
        DB::statement(
            "INSERT INTO device_status_history (id, device_id, from_status, to_status, reason)
             VALUES (?, ?, NULL, 'provisioned', 'initial provisioning')
             ON CONFLICT (id) DO NOTHING",
            [self::HIST_ID1, self::DEV_ID]
        );
        DB::statement(
            "INSERT INTO device_status_history (id, device_id, from_status, to_status, reason)
             VALUES (?, ?, 'provisioned', 'active', 'device activated')
             ON CONFLICT (id) DO NOTHING",
            [self::HIST_ID2, self::DEV_ID]
        );

        // 7 sensor (satu per sensor type), semua status active.
        $typeCodes = ['temp_air', 'humidity', 'pressure', 'wind_speed', 'wind_dir', 'rain_counter', 'solar_rad'];
        foreach ($typeCodes as $i => $code) {
            $n = $i + 1;
            DB::statement(
                'INSERT INTO sensors (id, serial_number, sensor_type_id, manufacturer, model, status)
                 VALUES (?, ?, ?, ?, ?, ?)
                 ON CONFLICT (id) DO NOTHING',
                [
                    $this->sensorId($n),
                    sprintf('WS-SENSOR-%s-%03d', strtoupper($code), $n),
                    (string) DB::table('sensor_types')->where('code', $code)->value('id'),
                    'Demo Instruments',
                    'D-Series',
                    'active',
                ]
            );

            // Pemasangan aktif pada device demo (partial unique: 1 aktif/sensor).
            DB::statement(
                "INSERT INTO sensor_installations (id, sensor_id, device_id, installed_at, removed_at)
                 VALUES (?, ?, ?, '2026-01-01 00:00:00+00', NULL)
                 ON CONFLICT (id) DO NOTHING",
                [sprintf('c0000000-0000-4000-8000-%012d', $n), $this->sensorId($n), self::DEV_ID]
            );

            // Kalibrasi identitas (offset 0, scale 1).
            DB::statement(
                'INSERT INTO sensor_calibrations (id, sensor_id, "offset", scale, effective_from, effective_to)
                 VALUES (?, ?, 0, 1, \'2026-01-01 00:00:00+00\', NULL)
                 ON CONFLICT (id) DO NOTHING',
                [sprintf('d0000000-0000-4000-8000-%012d', $n), $this->sensorId($n)]
            );
        }

        DB::statement(
            "INSERT INTO device_health (device_id, battery_voltage, rssi, firmware_version, uptime_seconds)
             VALUES (?, 4.12, -65, '1.0.0', 86400)
             ON CONFLICT (device_id) DO NOTHING",
            [self::DEV_ID]
        );
    }
}