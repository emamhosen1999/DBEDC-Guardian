<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Designation extends Model
{
    use HasFactory, SoftDeletes;

    protected static function boot()
    {
        parent::boot();

        static::saved(function ($designation) {
            \Illuminate\Support\Facades\Cache::forget('active_designations_list');
        });

        static::deleted(function ($designation) {
            \Illuminate\Support\Facades\Cache::forget('active_designations_list');
        });

        static::restored(function ($designation) {
            \Illuminate\Support\Facades\Cache::forget('active_designations_list');
        });
    }

    protected $table = 'designations';

    protected $fillable = [
        'title',
        'department_id',
        'parent_id',
        'hierarchy_level',
        'is_active',
    ];

    protected $casts = [
        'id' => 'integer',
        'department_id' => 'integer',
        'parent_id' => 'integer',
        'hierarchy_level' => 'integer',
        'is_active' => 'boolean',
    ];

    // Relationships
    public function department(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function users(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(User::class, 'designation_id');
    }

    public function parent(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Designation::class, 'parent_id');
    }

    // Scopes
    public function scopeActive($query)
    {
        return $query->where('is_active', true);
    }

    public function scopeOrderedByHierarchy($query)
    {
        return $query->orderBy('hierarchy_level', 'asc');
    }

    // Helper methods for hierarchy
    public function isTopLevel(): bool
    {
        return $this->hierarchy_level === 1 || $this->parent_id === null;
    }

    public function getHigherHierarchyDesignations()
    {
        return static::where('department_id', $this->department_id)
            ->where('hierarchy_level', '<', $this->hierarchy_level)
            ->where('is_active', true)
            ->orderBy('hierarchy_level', 'asc')
            ->get();
    }

    public function getEmployeeCountAttribute(): int
    {
        if ($this->relationLoaded('users')) {
            return $this->users->count();
        }

        if (array_key_exists('users_count', $this->attributes)) {
            return (int) $this->attributes['users_count'];
        }

        return $this->users()->count();
    }

    // Optional: customize array output for API responses
    public function toArray(): array
    {
        $array = parent::toArray();
        $array['department_name'] = optional($this->department)->name;
        $array['employee_count'] = $this->employee_count;

        return $array;
    }
}
