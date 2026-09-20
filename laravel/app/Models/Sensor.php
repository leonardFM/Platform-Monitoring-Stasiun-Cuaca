<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sensor extends Model
{
    use HasFactory;

    protected $table = 'sensors';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'serial_number',
        'sensor_type_id',
        'manufacturer',
        'model',
        'status',
    ];

    protected $casts = [
        'status' => 'string',
    ];

    public function sensorType(): BelongsTo
    {
        return $this->belongsTo(SensorType::class, 'sensor_type_id');
    }

    public function installations(): HasMany
    {
        return $this->hasMany(SensorInstallation::class, 'sensor_id');
    }

    public function calibrations(): HasMany
    {
        return $this->hasMany(SensorCalibration::class, 'sensor_id');
    }
}