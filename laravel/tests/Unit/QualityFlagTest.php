<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\SensorType;
use App\Models\SensorInstallation;
use App\Services\TelemetryProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class QualityFlagTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create sensor types with various valid ranges
        $this->tempType = SensorType::create([
            'code' => 'temp_air',
            'name' => 'Temperature',
            'unit' => '°C',
            'valid_min' => -60,
            'valid_max' => 70,
            'precision' => 0.1,
        ]);

        $this->humidityType = SensorType::create([
            'code' => 'humidity',
            'name' => 'Humidity',
            'unit' => '%',
            'valid_min' => 0,
            'valid_max' => 100,
            'precision' => 0.1,
        ]);

        $this->pressureType = SensorType::create([
            'code' => 'pressure',
            'name' => 'Pressure',
            'unit' => 'hPa',
            'valid_min' => 800,
            'valid_max' => 1100,
            'precision' => 0.01,
        ]);

        $this->windSpeedType = SensorType::create([
            'code' => 'wind_speed',
            'name' => 'Wind Speed',
            'unit' => 'm/s',
            'valid_min' => 0,
            'valid_max' => 100,
            'precision' => 0.1,
        ]);

        // Create a test device
        $this->device = Device::create([
            'device_code' => 'TEST-QUALITY-001',
            'name' => 'Test Quality Device',
            'location_id' => \App\Models\Location::create([
                'name' => 'Test Location',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->id,
            'status' => 'active',
        ]);

        // Create sensors
        $this->tempSensor = Sensor::create([
            'serial_number' => 'TEMP-SENSOR-001',
            'sensor_type_id' => $this->tempType->id,
            'status' => 'active',
        ]);

        $this->humiditySensor = Sensor::create([
            'serial_number' => 'HUMID-SENSOR-001',
            'sensor_type_id' => $this->humidityType->id,
            'status' => 'active',
        ]);

        $this->pressureSensor = Sensor::create([
            'serial_number' => 'PRESS-SENSOR-001',
            'sensor_type_id' => $this->pressureType->id,
            'status' => 'active',
        ]);

        $this->windSensor = Sensor::create([
            'serial_number' => 'WIND-SENSOR-001',
            'sensor_type_id' => $this->windSpeedType->id,
            'status' => 'active',
        ]);

        // Install sensors on device
        foreach ([$this->tempSensor, $this->humiditySensor, $this->pressureSensor, $this->windSensor] as $sensor) {
            SensorInstallation::create([
                'sensor_id' => $sensor->id,
                'device_id' => $this->device->id,
                'installed_at' => now(),
            ]);
        }

        $this->processor = app(TelemetryProcessor::class);
    }

    /** @test */
    public function value_within_valid_range_returns_good_flag()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Temperature within range (-10°C)
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            -10.0
        );

        $this->assertEquals('GOOD', $result['quality_flag']);
    }

    /** @test */
    public function temperature_below_min_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Temperature below -60°C
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            -100.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function temperature_above_max_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Temperature above 70°C
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            100.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function humidity_above_100_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Humidity 150% (from spec example)
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->humiditySensor->id,
            $deviceTime,
            1,
            150.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function humidity_negative_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->humiditySensor->id,
            $deviceTime,
            1,
            -10.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function pressure_below_min_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->pressureSensor->id,
            $deviceTime,
            1,
            500.0 // Below 800 hPa
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function wind_speed_negative_returns_out_of_range()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->windSensor->id,
            $deviceTime,
            1,
            -5.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
    }

    /** @test */
    public function sensor_error_value_negative_999_returns_sensor_error()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // -999 is the sensor error code
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            -999
        );

        $this->assertEquals('SENSOR_ERROR', $result['quality_flag']);
        $this->assertNull($result['corrected_value']);
    }

    /** @test */
    public function clock_drift_future_timestamp_returns_clock_drift()
    {
        $deviceTime = Carbon::now()->addHours(2); // 2 hours in future (from spec)

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals('CLOCK_DRIFT', $result['quality_flag']);
    }

    /** @test */
    public function clock_drift_future_timestamp_more_than_5_minutes()
    {
        $deviceTime = Carbon::now()->addMinutes(10); // > 5 minutes in future

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals('CLOCK_DRIFT', $result['quality_flag']);
    }

    /** @test */
    public function clock_drift_within_5_minutes_is_good()
    {
        $deviceTime = Carbon::now()->addMinutes(3); // Within 5 minutes tolerance

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals('GOOD', $result['quality_flag']);
    }

    /** @test */
    public function late_reading_older_than_1_hour_returns_late()
    {
        $deviceTime = Carbon::now()->subHours(2); // 2 hours ago

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals('LATE', $result['quality_flag']);
    }

    /** @test */
    public function late_reading_within_1_hour_is_good()
    {
        $deviceTime = Carbon::now()->subMinutes(30); // Within 1 hour

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals('GOOD', $result['quality_flag']);
    }

    /** @test */
    public function out_of_range_still_calculates_corrected_value()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Humidity 150% is out of range but corrected_value should still be computed
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->humiditySensor->id,
            $deviceTime,
            1,
            150.0
        );

        $this->assertEquals('OUT_OF_RANGE', $result['quality_flag']);
        $this->assertNotNull($result['corrected_value']);
        // Default calibration: corrected = raw * 1.0 + 0 = raw
        $this->assertEquals(150.0, $result['corrected_value']);
    }

    /** @test */
    public function sensor_error_does_not_calculate_corrected_value()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            -999
        );

        $this->assertEquals('SENSOR_ERROR', $result['quality_flag']);
        $this->assertNull($result['corrected_value']);
    }

    /** @test */
    public function valid_min_max_boundaries_are_inclusive()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        // Exactly at min boundary
        $resultMin = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            -60.0
        );
        $this->assertEquals('GOOD', $resultMin['quality_flag']);

        // Exactly at max boundary
        $resultMax = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            2,
            70.0
        );
        $this->assertEquals('GOOD', $resultMax['quality_flag']);
    }

    /** @test */
    public function sensor_without_valid_range_always_good()
    {
        // Create sensor type without valid_min/valid_max
        $unboundedType = SensorType::create([
            'code' => 'unbounded',
            'name' => 'Unbounded',
            'unit' => 'units',
            'valid_min' => null,
            'valid_max' => null,
        ]);

        $unboundedSensor = Sensor::create([
            'serial_number' => 'UNBOUND-001',
            'sensor_type_id' => $unboundedType->id,
            'status' => 'active',
        ]);

        SensorInstallation::create([
            'sensor_id' => $unboundedSensor->id,
            'device_id' => $this->device->id,
            'installed_at' => now(),
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $unboundedSensor->id,
            $deviceTime,
            1,
            999999.0 // Any value
        );

        $this->assertEquals('GOOD', $result['quality_flag']);
    }
}