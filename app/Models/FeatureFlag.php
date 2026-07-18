<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

/**
 * A single server-controlled flag / remote-config entry.
 *
 * @property string      $key
 * @property mixed       $value
 * @property string|null $description
 * @property bool        $is_enabled
 * @property string|null $role
 */
class FeatureFlag extends Model
{
    use HasFactory;

    protected $fillable = [
        'key',
        'value',
        'description',
        'is_enabled',
        'role',
    ];

    /**
     * Millisecond-precision timestamps. The flag resolver builds its cache key
     * from MAX(updated_at); at second granularity two edits within the same
     * second would collide on that key and the second edit would be invisible
     * until the TTL expired. Matches `timestamps(3)` in the migration.
     */
    protected $dateFormat = 'Y-m-d H:i:s.v';

    protected $casts = [
        // 'json' (not 'array') so scalars survive the round trip: a number flag
        // must come back as 300, not [300].
        'value' => 'json',
        'is_enabled' => 'boolean',
    ];

    /** A row with no role applies to every user. */
    public function isGlobal(): bool
    {
        return trim((string) $this->role) === '';
    }
}
