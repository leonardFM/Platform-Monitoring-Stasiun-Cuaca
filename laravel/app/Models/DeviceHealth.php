<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class DeviceHealth extends Model
{
    use HasFactory;

    protected $table = 'device_health';
    protected $primaryKey = 'device_id';
    public $incrementing = false;
    protected $keyType = 'string';

    public $timestamps = false;

    protected $fillable = [
        'device_id',
        'battery_voltage',
        'rssi',
        'firmware_version',
        'last_heartbeat_at',
        'uptime_seconds',
        'updated_at',
    ];

    protected $casts = [
        'battery_voltage' => 'decimal:2',
        'rssi' => 'decimal:2',
        'last_heartbeat_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function device(): BelongsTo
    {
        return $this->belongsTo(Device::class, 'device_id');
    }
}