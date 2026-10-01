<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class ShiftRotationPattern extends Model
{
    use HasFactory;

    protected $fillable = ['name', 'code', 'cycle_length_days', 'definition', 'is_active', 'created_by', 'department_id'];

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * The owning department; NULL = company-wide (managed with attendance.settings).
     */
    public function department()
    {
        return $this->belongsTo(Department::class);
    }

    protected $casts = [
        'department_id' => 'integer',
        'definition' => 'array',
        'cycle_length_days' => 'integer',
        'is_active' => 'boolean',
    ];
}
