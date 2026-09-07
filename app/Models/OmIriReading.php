<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OmIriReading extends Model
{
    use HasFactory;

    protected $table = 'om_iri_readings';

    protected $fillable = [
        'patrol_shift_id',
        'recorded_at',
        'chainage_km',
        'direction',
        'iri_value',
        'speed_kmh',
        'latitude',
        'longitude',
        'condition_band',
    ];

    protected $casts = [
        'recorded_at' => 'datetime',
        'chainage_km' => 'decimal:3',
        'iri_value' => 'decimal:2',
        'speed_kmh' => 'decimal:2',
        'latitude' => 'decimal:7',
        'longitude' => 'decimal:7',
    ];
}
