<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Pipeline pemrosesan payload F.1 (worker/RabbitMQ consumer).
 *
 * - Lookup device aktif + peta sensor terpasang (dengan kalibrasi efektif).
 * - Tiap sensor DIVIDES dalam readings[] => 1 baris di sensor_readings.
 * - Sensor yang tidak ada di readings[] atau tidak terpasang => diabaikan (null).
 * - rain_counter: delta counter * 0.2 mm; first reading => RAIN_INITIAL (0 mm);
 *   counter turun (device restart) => RAIN_RESET (0 mm, tidak minus).
 * - Kondisi di luar batas => quality flag OUT_OF_RANGE; -999 (kode error sensor)
 *   => SENSOR_ERROR.
 * - Idempotensi: ON CONFLICT (reading_key, device_time) DO NOTHING.
 * - Di akhir: update device_health + devices.last_seen_at/last_device_time.
 */
class TelemetryProcessor
{
    private const INSERT_CHUNK = 500;

    public function processItems(string $deviceId, ?string $fw, array $items): void
    {
        if ($items === []) {
            return;
        }

        $device = DB::selectOne(
            'SELECT id, device_code, status, firmware_version
             FROM devices WHERE id = ? AND deleted_at IS NULL',
            [$deviceId],
        );

        if (! $device) {
            Log::info('telemetry skipped: device not found', ['device_id' => $deviceId]);
            return;
        }
        if ($device->status !== 'active') {
            Log::info('telemetry skipped: device not active', ['device_id' => $deviceId, 'status' => $device->status]);
            return;
        }

        $sensorMap = $this->installedSensorMap($deviceId);

        $calibrations = $this->sensorCalibrations($deviceId);
        $rainSensorId = $sensorMap['rain_counter'] ?? null;

        DB::beginTransaction();

        try {
            // Serialisasi per-device supaya kalkulasi delta rain aman dari
            // proses worker lain yang menangani device yang sama.
            DB::statement(
                'SELECT pg_advisory_xact_lock(hashtextextended(?::text, 0))',
                [$deviceId],
            );

            $rainBaseline = $this->rainBaseline($rainSensorId, $deviceId, $items);
            $rainState = $rainBaseline; // null = belum pernah ada pembacaan rain

            $rows = [];
            $serverTime = Carbon::now();
            $maxDeviceTime = null;
            $lastItem = null;

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
                    $params,
                );
            };

            foreach ($items as $item) {
                $ts = Carbon::parse($item['ts']);
                $seq = (int) $item['seq'];
                $clockFlag = $this->clockFlag($ts);
                $shard = [
                    'ts' => $ts,
                    'seq' => $seq,
                    'battery_v' => $item['battery_v'] ?? null,
                    'rssi' => $item['rssi'] ?? null,
                    'readings' => $item['readings'],
                ];

                foreach ($item['readings'] as $code => $value) {
                    $sensorId = $sensorMap[$code] ?? null;
                    if ($sensorId === null) {
                        continue; // tidak terpasang / tidak dikirim -> null
                    }

                    $cal = $this->effectiveCalibration($calibrations[$sensorId] ?? [], $ts);

                    if ($code === 'rain_counter') {
                        [$raw, $corrected, $quality, $rainState] = $this->rainValue(
                            $value,
                            $rainState,
                        );
                    } else {
                        [$raw, $corrected, $quality] = $this->qualityAndCalibrate($code, $value, $cal);
                    }

                    $quality = $this->applyClockFlag($quality, $clockFlag);

                    $rows[] = [
                        $deviceId,
                        $sensorId,
                        $ts->toIso8601String(),
                        $serverTime->toIso8601String(),
                        $seq,
                        $raw,
                        $corrected,
                        $quality,
                    ];

                    if (count($rows) >= self::INSERT_CHUNK) {
                        $flush();
                    }
                }

                $maxDeviceTime = $maxDeviceTime === null || $ts->greaterThan($maxDeviceTime) ? $ts : $maxDeviceTime;
                $lastItem = $shard;
            }

            $flush();

            if ($maxDeviceTime !== null && $lastItem !== null) {
                $this->upsertHealth($deviceId, $fw, $lastItem);
                $this->touchDevice($deviceId, $fw, $maxDeviceTime);
            }

            DB::commit();

            Log::info('telemetry persisted', [
                'device_id' => $deviceId,
                'device_code' => $device->device_code,
                'items' => count($items),
                'rows' => self::rowCount($items),
            ]);
        } catch (Throwable $e) {
            if (DB::transactionLevel() > 0) {
                DB::rollBack();
            }

            throw $e;
        }
    }

    /**
     * Sensor terpasang per kode tipe, satu sensor aktif per tipe untuk routing payload.
     */
    private function installedSensorMap(string $deviceId): array
    {
        $rows = DB::select(
            'SELECT st.code, si.sensor_id
             FROM sensor_installations si
             JOIN sensors s ON s.id = si.sensor_id
             JOIN sensor_types st ON st.id = s.sensor_type_id
             WHERE si.device_id = ? AND si.removed_at IS NULL',
            [$deviceId],
        );

        $map = [];
        foreach ($rows as $row) {
            if (! isset($map[$row->code])) {
                $map[$row->code] = $row->sensor_id;
            }
        }

        return $map;
    }

    /**
     * Semua baris kalibrasi sensor device (beserta rentang efektifnya),
     * dipilih per ts di PHP.
     */
    private function sensorCalibrations(string $deviceId): array
    {
        $rows = DB::select(
            'SELECT si.sensor_id, sc.offset, sc.scale, sc.effective_from, sc.effective_to
             FROM sensor_installations si
             JOIN sensors s ON s.id = si.sensor_id
             LEFT JOIN sensor_calibrations sc ON sc.sensor_id = si.sensor_id
             WHERE si.device_id = ? AND si.removed_at IS NULL
             ORDER BY si.sensor_id, sc.effective_from',
            [$deviceId],
        );

        $map = [];
        foreach ($rows as $row) {
            $map[$row->sensor_id][] = [
                'offset' => (float) ($row->offset ?? 0),
                'scale' => (float) ($row->scale ?? 1),
                'effective_from' => $row->effective_from ? Carbon::parse($row->effective_from) : null,
                'effective_to' => $row->effective_to ? Carbon::parse($row->effective_to) : null,
            ];
        }

        return $map;
    }

    private function effectiveCalibration(array $list, Carbon $ts): ?array
    {
        $selected = null;
        foreach ($list as $cal) {
            if ($cal['effective_from'] && $cal['effective_from']->greaterThan($ts)) {
                continue;
            }
            if ($cal['effective_to'] && $cal['effective_to']->lessThanOrEqualTo($ts)) {
                continue;
            }
            if ($selected === null || $cal['effective_from'] >= $selected['effective_from']) {
                $selected = $cal;
            }
        }

        return $selected;
    }

    private function rainBaseline(?string $rainSensorId, string $deviceId, array $items): ?float
    {
        if ($rainSensorId === null) {
            return null;
        }

        $minTs = null;
        foreach ($items as $item) {
            $ts = Carbon::parse($item['ts']);
            if ($minTs === null || $ts->lessThan($minTs)) {
                $minTs = $ts;
            }
        }

        $baseline = DB::selectOne(
            'SELECT raw_value FROM sensor_readings
             WHERE sensor_id = ? AND device_id = ? AND device_time < ? AND raw_value >= 0
             ORDER BY device_time DESC LIMIT 1',
            [$rainSensorId, $deviceId, $minTs?->toIso8601String()],
        );

        return $baseline ? (float) $baseline->raw_value : null;
    }

    /**
     * Delta rain dalam mm (0.2 mm per tip), menangani pertama kali dan reset.
     *
     * @return array{0: float, 1: ?float, 2: string, 3: ?float} raw, mm, flag, stateCounter
     */
    private function rainValue(float $current, ?float $state): array
    {
        if ($current === -999.0 || $current < 0) {
            return [$current, null, 'SENSOR_ERROR', $current];
        }

        if ($state === null) {
            return [$current, 0.0, 'RAIN_INITIAL', $current];
        }

        $delta = $current - $state;
        if ($delta < 0) {
            return [$current, 0.0, 'RAIN_RESET', $current];
        }

        return [$current, round($delta * 0.2, 4), 'GOOD', $current];
    }

    /**
     * Quality flag + corrected (raw * scale + offset). -999 = kode error sensor.
     *
     * @return array{0: float, 1: ?float, 2: string} raw, corrected, flag
     */
    private function qualityAndCalibrate(string $code, float $value, ?array $cal): array
    {
        $isSensorError = $value === -999.0;

        [$lo, $hi] = self::sensorBounds()[$code];

        if ($isSensorError) {
            return [$value, null, 'SENSOR_ERROR'];
        }

        $scale = $cal['scale'] ?? 1.0;
        $offset = $cal['offset'] ?? 0.0;
        $corrected = $value * $scale + $offset;

        if ($value < $lo || $value > $hi) {
            return [$value, $corrected, 'OUT_OF_RANGE'];
        }

        return [$value, $corrected, 'GOOD'];
    }

    /**
     * Kebijakan jam device (F.3#1): ts terlalu maju => CLOCK_DRIFT,
     * ts terlalu tua (>7 hari) => LATE. Tetap diterima (tidak ditolak).
     */
    private function clockFlag(Carbon $ts): ?string
    {
        if ($ts->greaterThan(Carbon::now()->addMinutes(5))) {
            return 'CLOCK_DRIFT';
        }

        if ($ts->lessThan(Carbon::now()->subDays(7))) {
            return 'LATE';
        }

        return null;
    }

    private function applyClockFlag(string $quality, ?string $clockFlag): string
    {
        if ($clockFlag === null) {
            return $quality;
        }

        if (in_array($quality, ['GOOD', 'OUT_OF_RANGE'], true)) {
            return $clockFlag;
        }

        return $quality; // SENSOR_ERROR & RAIN_* tetap dipertahankan
    }

    public static function sensorBounds(): array
    {
        return [
            'temp_air' => [-60.0, 70.0],
            'humidity' => [0.0, 100.0],
            'pressure' => [800.0, 1100.0],
            'wind_speed' => [0.0, 100.0],
            'wind_dir' => [0.0, 359.0],
            'rain_counter' => [0.0, PHP_FLOAT_MAX],
            'solar_rad' => [0.0, 1400.0],
        ];
    }

    private function upsertHealth(string $deviceId, ?string $fw, array $lastItem): void
    {
        $battery = $lastItem['battery_v'];
        $rssi = $lastItem['rssi'];

        DB::update(
            'INSERT INTO device_health (
                device_id, battery_voltage, rssi, firmware_version,
                last_heartbeat_at, last_seq, updated_at
             ) VALUES (?, ?, ?, ?, now(), ?, now())
             ON CONFLICT (device_id) DO UPDATE SET
                battery_voltage = COALESCE(excluded.battery_voltage, device_health.battery_voltage),
                rssi = COALESCE(excluded.rssi, device_health.rssi),
                firmware_version = COALESCE(excluded.firmware_version, device_health.firmware_version),
                last_heartbeat_at = excluded.last_heartbeat_at,
                last_seq = excluded.last_seq,
                updated_at = now()',
            [$deviceId, $battery, $rssi, $fw, $lastItem['seq']],
        );
    }

    private function touchDevice(string $deviceId, ?string $fw, Carbon $ts): void
    {
        DB::update(
            'UPDATE devices SET
                last_seen_at = now(),
                last_device_time = GREATEST(COALESCE(last_device_time, ?), ?),
                firmware_version = COALESCE(?, firmware_version)
             WHERE id = ?',
            [$ts->toIso8601String(), $ts->toIso8601String(), $fw, $deviceId],
        );
    }

    private static function rowCount(array $items): int
    {
        $count = 0;
        foreach ($items as $item) {
            $count += count($item['readings']);
        }

        return $count;
    }
}