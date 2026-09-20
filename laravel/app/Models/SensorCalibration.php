<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class SensorCalibration extends Model
{
    use HasFactory;

    protected $table = 'sensor_calibrations';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'sensor_id',
        'gain',
        'offset',
        'valid_from',
        'valid_to',
    ];

    protected $casts = [
        'gain' => 'decimal:6',
        'offset' => 'decimal:6',
        'valid_from' => 'datetime',
        'valid_to' => 'datetime',
    ];

    public function sensor(): BelongsTo
    {
        return $this->belongsTo(Sensor::class, 'sensor_id');
    }
}