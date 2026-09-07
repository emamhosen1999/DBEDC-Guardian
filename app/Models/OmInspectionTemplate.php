<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class OmInspectionTemplate extends Model
{
    use HasFactory;

    protected $fillable = [
        'template_code',
        'name',
        'description',
        'asset_category',
        'checklist_sections',
        'max_score',
        'pass_threshold',
        'auto_create_defect_on_fail',
        'photo_required',
        'is_active',
        'created_by',
    ];

    protected $casts = [
        'checklist_sections' => 'array',
        'max_score' => 'integer',
        'pass_threshold' => 'integer',
        'auto_create_defect_on_fail' => 'boolean',
        'photo_required' => 'boolean',
        'is_active' => 'boolean',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
