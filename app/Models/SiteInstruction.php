<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * Site Instruction (SI) issued by the Independent Engineer.
 * Dhaka Bypass Expressway PPP — RHD / DBEDC / IE.
 */
class SiteInstruction extends Model
{
    use HasFactory;

    protected $fillable = [
        'si_number', 'ie_ref', 'department', 'category', 'location',
        'chainage_meters', 'description', 'summary', 'remarks',
        'status', 'issued_date', 'closed_date',
    ];

    protected $casts = [
        'issued_date' => 'date',
        'closed_date' => 'date',
        'chainage_meters' => 'integer',
    ];

    public function scopeOpen($query)
    {
        return $query->where('status', 'open');
    }
}
