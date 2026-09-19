<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class SensorTypeSeeder extends Seeder
{
    /**
     * Tipe sensor minimal yang wajib ada.
     * Idempoten: ON CONFLICT (id) DO NOTHING.
     */
    public function run(): void
    {
        $types = [
            // id, code, name, unit, valid_min, valid_max, precision
            ['b0000000-0000-4000-8000-000000000001', 'temp_air',    'Suhu Udara (Air Temperature)', '°C',   -100, 100,   0.1],
            ['b0000000-0000-4000-8000-000000000002', 'humidity',    'Kelembapan Relatif (Relative Humidity)', '%',   0,    100,   0.1],
            ['b0000000-0000-4000-8000-000000000003', 'pressure',    'Tekanan Udara (Pressure)',     'hPa',  100,  1100,  0.01],
            ['b0000000-0000-4000-8000-000000000004', 'wind_speed',  'Kecepatan Angin (Wind Speed)', 'm/s',  0,    100,   0.1],
            ['b0000000-0000-4000-8000-000000000005', 'wind_dir',    'Arah Angin (Wind Direction)',  '°',    0,    360,   1],
            ['b0000000-0000-4000-8000-000000000006', 'rain_counter','Curah Hujan (Rain Counter)',    'tip',  0,    1000000000, 1],
            ['b0000000-0000-4000-8000-000000000007', 'solar_rad',   'Radiasi Surya (Solar Radiation)', 'W/m²', 0,  2000,   1],
        ];

        foreach ($types as $t) {
            DB::statement(
                'INSERT INTO sensor_types (id, code, name, unit, valid_min, valid_max, precision)
                 VALUES (?, ?, ?, ?, ?, ?, ?)
                 ON CONFLICT (id) DO NOTHING',
                $t
            );
        }
    }
}