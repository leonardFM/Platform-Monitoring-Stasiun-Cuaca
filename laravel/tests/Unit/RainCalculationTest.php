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
use Tests\TestCase;

class RainCalculationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create sensor type for rain_counter
        $this->rainType = SensorType::create([
            'code' => 'rain_counter',
            'name' => 'Rain Counter',
            'unit' => 'tips',
            'valid_min' => 0,
            'valid_max' => 1000000000,
            'precision' => 1,
        ]);

        // Create a test device
        $this->device = Device::create([
            'device_code' => 'TEST-RAIN-001',
            'name' => 'Test Rain Device',
            'location_id' => \App\Models\Location::create([
                'name' => 'Test Location',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->id,
            'status' => 'active',
        ]);

        // Create a rain sensor
        $this->rainSensor = Sensor::create([
            'serial_number' => 'RAIN-SENSOR-001',
            'sensor_type_id' => $this->rainType->id,
            'status' => 'active',
        ]);

        // Install sensor on device
        SensorInstallation::create([
            'sensor_id' => $this->rainSensor->id,
            'device_id' => $this->device->id,
            'installed_at' => now(),
        ]);

        $this->processor = app(TelemetryProcessor::class);
    }

    /** @test */
    public function first_rain_reading_returns_rain_initial_with_zero_mm()
    {
        $deviceTime = Carbon::now()->subMinutes(10);
        $payload = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $deviceTime,
            'seq' => 1,
            'raw_value' => 100,
            'quality_flag' => 'GOOD',
        ];

        $result = $this->processor->processRainCounter($payload);

        $this->assertEquals('RAIN_INITIAL', $result['quality_flag']);
        $this->assertEquals(0.0, $result['corrected_value']);
    }

    /** @test */
    public function rain_counter_increase_calculates_correct_delta_mm()
    {
        $baseTime = Carbon::now()->subMinutes(10);

        // First reading (baseline)
        $payload1 = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 1000,
            'quality_flag' => 'GOOD',
        ];
        $this->processor->processRainCounter($payload1);

        // Second reading: counter increases by 5 tips = 1.0 mm (5 * 0.2)
        $payload2 = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => 1005,
            'quality_flag' => 'GOOD',
        ];
        $result = $this->processor->processRainCounter($payload2);

        $this->assertEquals('GOOD', $result['quality_flag']);
        $this->assertEquals(1.0, $result['corrected_value']); // 5 tips * 0.2 mm
    }

    /** @test */
    public function rain_counter_reset_returns_rain_reset_with_zero_mm()
    {
        $baseTime = Carbon::now()->subMinutes(10);

        // First reading
        $payload1 = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 5000,
            'quality_flag' => 'GOOD',
        ];
        $this->processor->processRainCounter($payload1);

        // Second reading: counter resets (device restart)
        $payload2 = [
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => 10, // Reset to low value
            'quality_flag' => 'GOOD',
        ];
        $result = $this->processor->processRainCounter($payload2);

        $this->assertEquals('RAIN_RESET', $result['quality_flag']);
        $this->assertEquals(0.0, $result['corrected_value']);
    }

    /** @test */
    public function multiple_resets_within_one_hour_still_correct()
    {
        $baseTime = Carbon::now()->subMinutes(50);

        // Initial reading
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 100,
            'quality_flag' => 'GOOD',
        ]);

        // First reset (restart 1)
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(15),
            'seq' => 2,
            'raw_value' => 5,
            'quality_flag' => 'GOOD',
        ]);

        // Second reset (restart 2) - should still handle correctly
        $result = $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(30),
            'seq' => 3,
            'raw_value' => 3,
            'quality_flag' => 'GOOD',
        ]);

        $this->assertEquals('RAIN_RESET', $result['quality_flag']);
        $this->assertEquals(0.0, $result['corrected_value']);
    }

    /** @test */
    public function rain_counter_same_value_returns_zero_mm()
    {
        $baseTime = Carbon::now()->subMinutes(10);

        // First reading
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 100,
            'quality_flag' => 'GOOD',
        ]);

        // Same counter value (no tips)
        $result = $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => 100,
            'quality_flag' => 'GOOD',
        ]);

        $this->assertEquals('GOOD', $result['quality_flag']);
        $this->assertEquals(0.0, $result['corrected_value']);
    }

    /** @test */
    public function rain_counter_large_increase_calculates_correctly()
    {
        $baseTime = Carbon::now()->subMinutes(10);

        // First reading
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 0,
            'quality_flag' => 'GOOD',
        ]);

        // Large increase: 50000 tips = 10000 mm
        $result = $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => 50000,
            'quality_flag' => 'GOOD',
        ]);

        $this->assertEquals('GOOD', $result['quality_flag']);
        $this->assertEquals(10000.0, $result['corrected_value']); // 50000 * 0.2
    }

    /** @test */
    public function sensor_error_raw_value_returns_sensor_error_flag()
    {
        $baseTime = Carbon::now()->subMinutes(10);

        // First reading (valid)
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 100,
            'quality_flag' => 'GOOD',
        ]);

        // Second reading with -999 (sensor error code)
        $result = $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $this->rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => -999,
            'quality_flag' => 'SENSOR_ERROR',
        ]);

        $this->assertEquals('SENSOR_ERROR', $result['quality_flag']);
        $this->assertNull($result['corrected_value']);
    }
}