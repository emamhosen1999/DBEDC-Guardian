<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftRotationPattern extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'cycle_length_days', 'definition', 'is_active', 'created_by'];

    public function creator()
    {
        return $this->belongsTo(\App\Models\User::class, 'created_by');
    }

    protected $casts = [
        'definition' => 'array',
        'cycle_length_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
