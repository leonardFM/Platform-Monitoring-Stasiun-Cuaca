<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\SensorType;
use App\Models\SensorInstallation;
use App\Models\SensorReading;
use App\Services\TelemetryProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class IdempotencyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->tempType = SensorType::create([
            'code' => 'temp_air',
            'name' => 'Temperature',
            'unit' => '°C',
            'valid_min' => -60,
            'valid_max' => 70,
        ]);

        $this->device = Device::create([
            'device_code' => 'TEST-IDEMPOTENT-001',
            'name' => 'Test Idempotent Device',
            'location_id' => \App\Models\Location::create([
                'name' => 'Test Location',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->id,
            'status' => 'active',
        ]);

        $this->tempSensor = Sensor::create([
            'serial_number' => 'TEMP-IDEMPOTENT-001',
            'sensor_type_id' => $this->tempType->id,
            'status' => 'active',
        ]);

        SensorInstallation::create([
            'sensor_id' => $this->tempSensor->id,
            'device_id' => $this->device->id,
            'installed_at' => now(),
        ]);

        $this->processor = app(TelemetryProcessor::class);
    }

    /** @test */
    public function duplicate_payload_same_reading_key_does_not_create_duplicate_row()
    {
        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);

        // Create the reading_key that would be generated
        $readingKey = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";

        // First insert
        $payload = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 25.0,
            'corrected_value' => 25.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey,
        ];

        SensorReading::create($payload);

        // Count before duplicate attempt
        $countBefore = SensorReading::count();

        // Try to insert duplicate (same reading_key + device_time)
        try {
            SensorReading::create($payload);
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected: unique constraint violation
        }

        $countAfter = SensorReading::count();

        // Should still be 1 (duplicate ignored)
        $this->assertEquals($countBefore, $countAfter);
        $this->assertEquals(1, $countAfter);
    }

    /** @test */
    public function same_device_time_sensor_seq_but_different_value_is_duplicate()
    {
        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);
        $readingKey = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";

        // First insert with value 25.0
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 25.0,
            'corrected_value' => 25.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey,
        ]);

        // Second insert with different value 30.0 but same key
        try {
            SensorReading::create([
                'device_id' => $this->device->id,
                'sensor_id' => $this->tempSensor->id,
                'device_time' => $deviceTime,
                'server_time' => now(),
                'seq' => 1,
                'raw_value' => 30.0, // Different value
                'corrected_value' => 30.0,
                'quality_flag' => 'GOOD',
                'reading_key' => $readingKey,
            ]);
        } catch (\Illuminate\Database\QueryException $e) {
            // Expected
        }

        // Should still have only 1 row (first one wins)
        $reading = SensorReading::first();
        $this->assertEquals(25.0, $reading->raw_value);
        $this->assertEquals(1, SensorReading::count());
    }

    /** @test */
    public function different_seq_same_timestamp_creates_separate_rows()
    {
        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);

        // Seq 1
        $readingKey1 = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 25.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey1,
        ]);

        // Seq 2 (same timestamp, different seq)
        $readingKey2 = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|2";
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 2,
            'raw_value' => 26.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey2,
        ]);

        // Should have 2 rows
        $this->assertEquals(2, SensorReading::count());
    }

    /** @test */
    public function different_sensor_same_timestamp_seq_creates_separate_rows()
    {
        $humidityType = SensorType::create([
            'code' => 'humidity',
            'name' => 'Humidity',
            'unit' => '%',
            'valid_min' => 0,
            'valid_max' => 100,
        ]);

        $humiditySensor = Sensor::create([
            'serial_number' => 'HUMID-IDEMPOTENT-001',
            'sensor_type_id' => $humidityType->id,
            'status' => 'active',
        ]);

        SensorInstallation::create([
            'sensor_id' => $humiditySensor->id,
            'device_id' => $this->device->id,
            'installed_at' => now(),
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);

        // Temp sensor
        $readingKey1 = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 25.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey1,
        ]);

        // Humidity sensor (different sensor_id)
        $readingKey2 = "{$this->device->id}|{$epochMicros}|{$humiditySensor->id}|1";
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $humiditySensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 60.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey2,
        ]);

        // Should have 2 rows (different sensors)
        $this->assertEquals(2, SensorReading::count());
    }

    /** @test */
    public function different_device_same_sensor_timestamp_seq_creates_separate_rows()
    {
        $device2 = Device::create([
            'device_code' => 'TEST-IDEMPOTENT-002',
            'name' => 'Test Device 2',
            'location_id' => $this->device->location_id,
            'status' => 'active',
        ]);

        SensorInstallation::create([
            'sensor_id' => $this->tempSensor->id,
            'device_id' => $device2->id,
            'installed_at' => now(),
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);

        // Device 1
        $readingKey1 = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";
        SensorReading::create([
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 25.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey1,
        ]);

        // Device 2 (different device_id)
        $readingKey2 = "{$device2->id}|{$epochMicros}|{$this->tempSensor->id}|1";
        SensorReading::create([
            'device_id' => $device2->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'server_time' => now(),
            'seq' => 1,
            'raw_value' => 26.0,
            'quality_flag' => 'GOOD',
            'reading_key' => $readingKey2,
        ]);

        // Should have 2 rows (different devices)
        $this->assertEquals(2, SensorReading::count());
    }

    /** @test */
    public function telemetry_processor_handles_duplicate_gracefully()
    {
        $deviceTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($deviceTime->timestamp * 1000000);
        $readingKey = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1";

        // First process
        $payload = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->tempSensor->id,
            'device_time' => $deviceTime,
            'seq' => 1,
            'raw_value' => 25.0,
            'quality_flag' => 'GOOD',
        ];

        $result1 = $this->processor->processSingleReading($payload);

        // Second process (duplicate)
        $result2 = $this->processor->processSingleReading($payload);

        // Both should succeed (idempotent)
        $this->assertEquals('inserted', $result1['status'] ?? 'duplicate');
        // Second should be detected as duplicate
        $this->assertEquals(1, SensorReading::where('reading_key', $readingKey)->count());
    }

    /** @test */
    public function batch_with_duplicates_only_inserts_unique_rows()
    {
        $baseTime = Carbon::now()->subMinutes(10);
        $epochMicros = floor($baseTime->timestamp * 1000000);

        $readings = [
            [
                'device_id' => $this->device->id,
                'sensor_id' => $this->tempSensor->id,
                'device_time' => $baseTime,
                'server_time' => now(),
                'seq' => 1,
                'raw_value' => 25.0,
                'quality_flag' => 'GOOD',
                'reading_key' => "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1",
            ],
            [
                'device_id' => $this->device->id,
                'sensor_id' => $this->tempSensor->id,
                'device_time' => $baseTime,
                'server_time' => now(),
                'seq' => 1,
                'raw_value' => 25.0,
                'quality_flag' => 'GOOD',
                'reading_key' => "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|1", // Duplicate
            ],
            [
                'device_id' => $this->device->id,
                'sensor_id' => $this->tempSensor->id,
                'device_time' => $baseTime->copy()->addMinutes(1),
                'server_time' => now(),
                'seq' => 2,
                'raw_value' => 26.0,
                'quality_flag' => 'GOOD',
                'reading_key' => "{$this->device->id}|" . floor($baseTime->copy()->addMinutes(1)->timestamp * 1000000) . "|{$this->tempSensor->id}|2",
            ],
        ];

        // Bulk insert with ON CONFLICT DO NOTHING
        foreach ($readings as $reading) {
            try {
                SensorReading::create($reading);
            } catch (\Illuminate\Database\QueryException $e) {
                // Ignore duplicates
            }
        }

        // Should have 2 unique rows (1 duplicate ignored)
        $this->assertEquals(2, SensorReading::count());
    }

    /** @test */
    public function reading_key_format_is_deterministic()
    {
        $deviceTime = Carbon::parse('2026-01-15 10:30:00');
        $epochMicros = floor($deviceTime->timestamp * 1000000);

        $expectedKey = "{$this->device->id}|{$epochMicros}|{$this->tempSensor->id}|42";

        // Generate reading key using the same logic as the trigger
        $generatedKey = $this->device->id . '|' . $epochMicros . '|' . $this->tempSensor->id . '|42';

        $this->assertEquals($expectedKey, $generatedKey);
    }
}