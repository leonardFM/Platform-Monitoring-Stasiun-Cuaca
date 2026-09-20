<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class Device extends Model
{
    use HasFactory;

    protected $table = 'devices';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'device_code',
        'name',
        'location_id',
        'status',
        'firmware_version',
        'last_seen_at',
        'last_device_time',
    ];

    protected $casts = [
        'last_seen_at' => 'datetime',
        'last_device_time' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function locationRelation(): BelongsTo
    {
        return $this->belongsTo(Location::class, 'location_id');
    }

    public function getLocationAttribute(): ?array
    {
        $loc = $this->locationRelation;
        if (! $loc) {
            return null;
        }
        return [
            'id' => (string) $loc->id,
            'name' => $loc->name,
            'latitude' => (float) $loc->latitude,
            'longitude' => (float) $loc->longitude,
            'altitude' => $loc->altitude ? (float) $loc->altitude : null,
        ];
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(DeviceCredential::class, 'device_id');
    }

    public function health(): HasOne
    {
        return $this->hasOne(DeviceHealth::class, 'device_id');
    }

    public function statusHistory(): HasMany
    {
        return $this->hasMany(DeviceStatusHistory::class, 'device_id')->latest('created_at');
    }

    public function sensorInstallations(): HasMany
    {
        return $this->hasMany(SensorInstallation::class, 'device_id');
    }
}