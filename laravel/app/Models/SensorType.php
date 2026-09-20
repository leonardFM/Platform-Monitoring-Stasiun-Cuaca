<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SensorType extends Model
{
    use HasFactory;

    protected $table = 'sensor_types';
    protected $primaryKey = 'id';
    public $incrementing = false;
    protected $keyType = 'string';

    protected $fillable = [
        'code',
        'name',
        'unit',
        'valid_min',
        'valid_max',
        'precision',
    ];

    protected $casts = [
        'valid_min' => 'decimal:6',
        'valid_max' => 'decimal:6',
        'precision' => 'decimal:6',
    ];

    protected static function booted(): void
    {
        static::creating(function ($model) {
            if (empty($model->id)) {
                $model->id = (string) \Illuminate\Support\Str::uuid();
            }
        });
    }

    public function sensors(): HasMany
    {
        return $this->hasMany(Sensor::class, 'sensor_type_id');
    }
}