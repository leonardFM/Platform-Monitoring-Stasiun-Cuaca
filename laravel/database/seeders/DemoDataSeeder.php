<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Data demo minimal: 3 device, 7 tipe sensor, 7 hari data historis.
 * Idempoten: semua INSERT memakai ON CONFLICT DO NOTHING.
 */
class DemoDataSeeder extends Seeder
{
    // Fixed UUIDs untuk idempotensi
    private const LOC_IDS = [
        '11111111-1111-4111-8111-111111111111',  // Jakarta Pusat
        '22222222-2222-4222-8222-222222222222',  // Bandung Barat
        '33333333-3333-4333-8333-333333333333',  // Surabaya Timur
        '44444444-4444-4444-8444-444444444444',  // Garut (WS-GRT-001)
    ];

    private const DEV_IDS = [
        'aaaaaaaa-0000-4000-8000-000000000001',  // DEMO-001
        'bbbbbbbb-0000-4000-8000-000000000001',  // DEMO-002
        'cccccccc-0000-4000-8000-000000000001',  // DEMO-003
        'dddddddd-0000-4000-8000-000000000001',  // WS-GRT-001 (Garut)
    ];

    private const DEV_CODES = ['DEMO-001', 'DEMO-002', 'DEMO-003', 'WS-GRT-001'];
    private const DEV_NAMES = ['Jakarta Pusat', 'Bandung Barat', 'Surabaya Timur', 'Garut'];

    private function sensorId(int $n): string
    {
        // Valid UUID v4: 8-4-4-4-12 hex chars
        $prefixes = ['a', 'b', 'c', 'd', 'e', 'f'];
        $prefix = $prefixes[($n - 1) % count($prefixes)];
        return sprintf('%s0000000-0000-4000-8000-%012d', $prefix, $n);
    }

    public function run(): void
    {
        // 3 lokasi + Garut
        $locations = [
            [self::LOC_IDS[0], 'Jakarta Pusat', -6.200000, 106.816667, 8.00],
            [self::LOC_IDS[1], 'Bandung Barat', -6.917464, 107.619123, 768.00],
            [self::LOC_IDS[2], 'Surabaya Timur', -7.257472, 112.752088, 3.00],
            [self::LOC_IDS[3], 'Garut', -7.214000, 107.906000, 717.00],
        ];
        foreach ($locations as $loc) {
            DB::statement(
                'INSERT INTO locations (id, name, latitude, longitude, altitude)
                 VALUES (?, ?, ?, ?, ?)
                 ON CONFLICT (id) DO NOTHING',
                $loc
            );
        }

        // 4 device aktif (termasuk WS-GRT-001 untuk testing F.1 payload)
        for ($d = 0; $d < 4; $d++) {
            $devId = self::DEV_IDS[$d];
            $locId = self::LOC_IDS[$d];
            
            // WS-GRT-001 pakai fw 1.4.2 sesuai spec F.1, lain 1.0.0
            $fwVersion = $d === 3 ? '1.4.2' : '1.0.0';
            
            // Setiap device pakai API key unik (unique constraint pada api_key)
            $apiKey = $d === 0
                ? (string) env('DEMO_DEVICE_API_KEY', 'dev_demo_weather_station_2024')
                : 'dev_demo_' . strtolower(self::DEV_CODES[$d]) . '_2024';
            $credId = sprintf('c0000000-0000-4000-8000-%012d', $d + 1);
            $histId1 = sprintf('e0000000-0000-4000-8000-%012d', $d * 2 + 1);
            $histId2 = sprintf('f0000000-0000-4000-8000-%012d', $d * 2 + 2);

            DB::statement(
                "INSERT INTO devices (id, device_code, name, location_id, status, firmware_version)
                 VALUES (?, ?, ?, ?, 'active', ?)
                 ON CONFLICT (id) DO NOTHING",
                [$devId, self::DEV_CODES[$d], self::DEV_NAMES[$d], $locId, $fwVersion]
            );

            DB::statement(
                'INSERT INTO device_credentials (id, device_id, api_key, secret_hash)
                 VALUES (?, ?, ?, ?)
                 ON CONFLICT (id) DO NOTHING',
                [$credId, $devId, $apiKey, hash('sha256', $apiKey)]
            );

            // Status history: provisioned -> active
            DB::statement(
                "INSERT INTO device_status_history (id, device_id, from_status, to_status, reason)
                 VALUES (?, ?, NULL, 'provisioned', 'initial provisioning')
                 ON CONFLICT (id) DO NOTHING",
                [$histId1, $devId]
            );
            DB::statement(
                "INSERT INTO device_status_history (id, device_id, from_status, to_status, reason)
                 VALUES (?, ?, 'provisioned', 'active', 'device activated')
                 ON CONFLICT (id) DO NOTHING",
                [$histId2, $devId]
            );

            // Device health
            DB::statement(
                "INSERT INTO device_health (device_id, battery_voltage, rssi, firmware_version, uptime_seconds)
                 VALUES (?, 4.12, -65, ?, 86400)
                 ON CONFLICT (device_id) DO NOTHING",
                [$devId, $fwVersion]
            );
        }

        // 7 sensor types sudah di-seed oleh SensorTypeSeeder
        $typeCodes = ['temp_air', 'humidity', 'pressure', 'wind_speed', 'wind_dir', 'rain_counter', 'solar_rad'];

        // Buat 7 sensor per device (total 21 sensor)
        foreach (self::DEV_IDS as $devIdx => $devId) {
            foreach ($typeCodes as $i => $code) {
                $n = $devIdx * 7 + $i + 1;
                $sensorId = $this->sensorId($n);

                DB::statement(
                    'INSERT INTO sensors (id, serial_number, sensor_type_id, manufacturer, model, status)
                     VALUES (?, ?, ?, ?, ?, ?)
                     ON CONFLICT (id) DO NOTHING',
                    [
                        $sensorId,
                        sprintf('WS-%s-%03d', strtoupper($code), $n),
                        (string) DB::table('sensor_types')->where('code', $code)->value('id'),
                        'Demo Instruments',
                        'D-Series',
                        'active',
                    ]
                );

                // Installasi aktif
                $instId = sprintf('d0000000-0000-4000-8000-%012d', $n);
                DB::statement(
                    "INSERT INTO sensor_installations (id, sensor_id, device_id, installed_at, removed_at)
                     VALUES (?, ?, ?, '2026-01-01 00:00:00+00', NULL)
                     ON CONFLICT (id) DO NOTHING",
                    [$instId, $sensorId, $devId]
                );

                // Kalibrasi identitas
                $calId = sprintf('e0000000-0000-4000-8000-%012d', $n);
                DB::statement(
                    'INSERT INTO sensor_calibrations (id, sensor_id, "offset", scale, effective_from, effective_to)
                     VALUES (?, ?, 0, 1, \'2026-01-01 00:00:00+00\', NULL)
                     ON CONFLICT (id) DO NOTHING',
                    [$calId, $sensorId]
                );
            }
        }

        // Data historis 7 hari (per menit) untuk setiap sensor
        $this->seedHistoricalReadings();
    }

    private function seedHistoricalReadings(): void
    {
        $now = new \DateTime('2026-09-19 00:00:00', new \DateTimeZone('UTC'));
        $start = clone $now;
        $start->modify('-7 days');
        $end = clone $now;

        // Ambil semua sensor yang terinstall di device demo
        $installations = DB::table('sensor_installations')
            ->join('devices', 'sensor_installations.device_id', '=', 'devices.id')
            ->whereIn('devices.id', self::DEV_IDS)
            ->whereNull('sensor_installations.removed_at')
            ->select('sensor_installations.sensor_id', 'sensor_installations.device_id')
            ->get();

        // Nilai baseline per tipe sensor
        $baseValues = [
            'temp_air'    => 27.5,
            'humidity'    => 75.0,
            'pressure'    => 1013.25,
            'wind_speed'  => 3.2,
            'wind_dir'    => 180,
            'rain_counter'=> 0,
            'solar_rad'   => 450,
        ];

        // Get sensor type code for each sensor
        $sensorTypes = DB::table('sensors')
            ->join('sensor_types', 'sensors.sensor_type_id', '=', 'sensor_types.id')
            ->whereIn('sensors.id', $installations->pluck('sensor_id'))
            ->pluck('sensor_types.code', 'sensors.id')
            ->toArray();

        $seq = 1;
        $rainCounters = []; // track counter per sensor
        $rows = [];         // buffer untuk chunked insert

        $flush = function () use (&$rows): void {
            if ($rows === []) {
                return;
            }
            $chunk = array_splice($rows, 0);
            $values = implode(',', array_fill(0, count($chunk), '(?, ?, ?, ?, ?, ?, ?, ?)'));
            $params = [];
            foreach ($chunk as $row) {
                foreach ($row as $value) {
                    $params[] = $value;
                }
            }
            DB::statement(
                'INSERT INTO sensor_readings (device_id, sensor_id, device_time, server_time, seq, raw_value, corrected_value, quality_flag)
                 VALUES ' . $values . '
                 ON CONFLICT (reading_key, device_time) DO NOTHING',
                $params
            );
        };

        for ($dt = clone $start; $dt <= $end; $dt->modify('+1 minute')) {
            $deviceTime = $dt->format('Y-m-d H:i:s') . '+00';
            $serverTime = (clone $dt)->modify('+1 second')->format('Y-m-d H:i:s') . '+00';

            foreach ($installations as $inst) {
                $sensorId = $inst->sensor_id;
                $deviceId = $inst->device_id;
                $typeCode = $sensorTypes[$sensorId] ?? 'temp_air';
                $base = $baseValues[$typeCode] ?? 0;

                // Variasi kecil ±5%
                $variation = ($base * 0.05) * (mt_rand(-100, 100) / 100);
                $raw = round($base + $variation, 2);

                // Rain counter: increment secara acak (tip = 0.2mm)
                if ($typeCode === 'rain_counter') {
                    $rainPrev = $rainCounters[$sensorId] ?? null;
                    $rainCounters[$sensorId] = ($rainCounters[$sensorId] ?? 0) + mt_rand(0, 1);
                    $raw = $rainCounters[$sensorId];
                }

                // Solar rad: 0 di malam
                if ($typeCode === 'solar_rad') {
                    $hour = (int) $dt->format('H');
                    $raw = ($hour >= 6 && $hour <= 18) ? $raw : 0;
                }

                // Quality flag: 95% GOOD
                $quality = (mt_rand(1, 100) <= 95) ? 'GOOD' : 'OUT_OF_RANGE';

                // Sensor error code yang dikirim firmware kadang -999
                // (bukan bagian dari data historis demo ini).

                $corrected = $raw;
                $flag = $quality;

                // Rain: delta counter * 0.2 mm (sama dengan pipeline live).
                if ($typeCode === 'rain_counter') {
                    $delta = (float) (($rainPrev === null || $raw < $rainPrev) ? 0 : $raw - $rainPrev);
                    $corrected = round($delta * 0.2, 4);
                    $flag = $rainPrev === null ? 'RAIN_INITIAL' : 'GOOD';
                }

                $rows[] = [$deviceId, $sensorId, $deviceTime, $serverTime, $seq++, $raw, $corrected, $flag];

                if (count($rows) >= 1000) {
                    $flush();
                }
            }
        }
        $flush();

        // Warm aggregates (1h/1d) langsung saat seed, selaras dengan
        // job terjadwal `aggregates:recalculate` (setiap 5 menit).
        app(\App\Services\AggregateRecalculator::class)->recalculate();
    }
}