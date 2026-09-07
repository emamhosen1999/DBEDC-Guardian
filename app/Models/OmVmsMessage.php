<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class OmVmsMessage extends Model
{
    use HasFactory;

    protected $attributes = ['lock_version' => 0];

    protected $fillable = [
        'vms_code',
        'location',
        'message_line1',
        'message_line2',
        'type',
        'is_active',
        'updated_by_operator_at',
        'lock_version',
    ];

    protected $casts = [
        'is_active' => 'boolean',
        'updated_by_operator_at' => 'datetime',
        'lock_version' => 'integer',
    ];
}
