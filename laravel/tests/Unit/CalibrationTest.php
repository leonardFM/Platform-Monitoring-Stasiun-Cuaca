<?php

namespace Tests\Unit;

use App\Models\Device;
use App\Models\Sensor;
use App\Models\SensorType;
use App\Models\SensorInstallation;
use App\Models\SensorCalibration;
use App\Services\TelemetryProcessor;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CalibrationTest extends TestCase
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
            'precision' => 0.1,
        ]);

        $this->device = Device::create([
            'device_code' => 'TEST-CALIB-001',
            'name' => 'Test Calibration Device',
            'location_id' => \App\Models\Location::create([
                'name' => 'Test Location',
                'latitude' => -6.2,
                'longitude' => 106.8,
            ])->id,
            'status' => 'active',
        ]);

        $this->tempSensor = Sensor::create([
            'serial_number' => 'TEMP-CALIB-001',
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
    public function no_calibration_returns_raw_value_as_corrected()
    {
        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        $this->assertEquals(25.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_with_offset_applies_correctly()
    {
        // Create calibration: offset = -0.5, scale = 1.0
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -0.5,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // corrected = raw * scale + offset = 25.0 * 1.0 + (-0.5) = 24.5
        $this->assertEquals(24.5, $result['corrected_value']);
    }

    /** @test */
    public function calibration_with_scale_applies_correctly()
    {
        // Create calibration: offset = 0, scale = 1.02
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => 0,
            'scale' => 1.02,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // corrected = raw * scale + offset = 25.0 * 1.02 + 0 = 25.5
        $this->assertEquals(25.5, $result['corrected_value']);
    }

    /** @test */
    public function calibration_with_both_offset_and_scale_applies_correctly()
    {
        // Create calibration: offset = -0.2, scale = 1.01
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -0.2,
            'scale' => 1.01,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // corrected = raw * scale + offset = 25.0 * 1.01 + (-0.2) = 25.25 - 0.2 = 25.05
        $this->assertEquals(25.05, $result['corrected_value']);
    }

    /** @test */
    public function calibration_effective_from_future_not_applied()
    {
        // Calibration starts tomorrow
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => now()->addDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10); // Today

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should NOT apply future calibration
        $this->assertEquals(25.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_effective_to_past_not_applied()
    {
        // Calibration ended yesterday
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => now()->subDays(2),
            'effective_to' => now()->subDay(),
        ]);

        $deviceTime = Carbon::now()->subMinutes(10); // Today (after effective_to)

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should NOT apply expired calibration
        $this->assertEquals(25.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_within_effective_period_is_applied()
    {
        // Calibration valid for today
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => now()->addDay(),
        ]);

        $deviceTime = Carbon::now()->subMinutes(10); // Within period

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should apply calibration
        $this->assertEquals(24.0, $result['corrected_value']); // 25.0 - 1.0
    }

    /** @test */
    public function multiple_calibrations_uses_correct_one_by_time()
    {
        // Old calibration (expired)
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -5.0,
            'scale' => 1.0,
            'effective_from' => now()->subDays(10),
            'effective_to' => now()->subDays(5),
        ]);

        // Current calibration
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -0.5,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        // Future calibration
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -2.0,
            'scale' => 1.0,
            'effective_from' => now()->addDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10); // Now

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should use current calibration (offset -0.5)
        $this->assertEquals(24.5, $result['corrected_value']);
    }

    /** @test */
    public function calibration_at_exact_effective_from_boundary_is_applied()
    {
        $startTime = now()->subMinutes(10);

        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => $startTime,
            'effective_to' => null,
        ]);

        $deviceTime = $startTime->copy(); // Exactly at effective_from

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should apply (inclusive boundary)
        $this->assertEquals(24.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_at_exact_effective_to_boundary_is_not_applied()
    {
        $endTime = now()->subMinutes(10);

        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => $endTime,
        ]);

        $deviceTime = $endTime->copy(); // Exactly at effective_to

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // effective_to is exclusive in the query (device_time < effective_to)
        // So at exact effective_to, it should NOT apply
        $this->assertEquals(25.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_with_null_effective_to_is_open_ended()
    {
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -1.0,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => null, // Open-ended
        ]);

        $deviceTime = now()->addDay(); // Far future

        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            25.0
        );

        // Should still apply (no end date)
        $this->assertEquals(24.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_applies_before_range_check()
    {
        // Create calibration that brings out-of-range value into range
        SensorCalibration::create([
            'sensor_id' => $this->tempSensor->id,
            'offset' => -20.0,
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        $deviceTime = Carbon::now()->subMinutes(10);

        // Raw value 80°C (out of range for temp: max 70)
        // But corrected = 80 - 20 = 60°C (in range)
        $result = $this->processor->qualityAndCalibrate(
            $this->device->id,
            $this->tempSensor->id,
            $deviceTime,
            1,
            80.0
        );

        // Should be GOOD because corrected value is in range
        $this->assertEquals('GOOD', $result['quality_flag']);
        $this->assertEquals(60.0, $result['corrected_value']);
    }

    /** @test */
    public function calibration_does_not_affect_rain_counter_calculation()
    {
        $rainType = SensorType::create([
            'code' => 'rain_counter',
            'name' => 'Rain Counter',
            'unit' => 'tips',
            'valid_min' => 0,
            'valid_max' => 1000000000,
        ]);

        $rainSensor = Sensor::create([
            'serial_number' => 'RAIN-CALIB-001',
            'sensor_type_id' => $rainType->id,
            'status' => 'active',
        ]);

        SensorInstallation::create([
            'sensor_id' => $rainSensor->id,
            'device_id' => $this->device->id,
            'installed_at' => now(),
        ]);

        // Add calibration to rain sensor
        SensorCalibration::create([
            'sensor_id' => $rainSensor->id,
            'offset' => 100, // This should NOT affect rain delta calculation
            'scale' => 1.0,
            'effective_from' => now()->subDay(),
            'effective_to' => null,
        ]);

        $baseTime = Carbon::now()->subMinutes(10);

        // First reading
        $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $rainSensor->id,
            'device_time' => $baseTime,
            'seq' => 1,
            'raw_value' => 1000,
            'quality_flag' => 'GOOD',
        ]);

        // Second reading: counter increases by 5 tips
        $result = $this->processor->processRainCounter([
            'device_id' => $this->device->id,
            'sensor_id' => $rainSensor->id,
            'device_time' => $baseTime->copy()->addMinutes(5),
            'seq' => 2,
            'raw_value' => 1005,
            'quality_flag' => 'GOOD',
        ]);

        // Rain delta should be based on RAW counter difference, not calibrated
        // 5 tips * 0.2 mm = 1.0 mm
        $this->assertEquals(1.0, $result['corrected_value']);
    }
}