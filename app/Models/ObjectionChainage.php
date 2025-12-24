<?php

namespace App\Models;

use App\Traits\ChainageMatcher;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ObjectionChainage extends Model
{
    use ChainageMatcher, HasFactory;

    /**
     * Entry type constants
     */
    public const TYPE_SPECIFIC = 'specific';

    public const TYPE_RANGE_START = 'range_start';

    public const TYPE_RANGE_END = 'range_end';

    /**
     * Valid entry types
     */
    public static array $entryTypes = [
        self::TYPE_SPECIFIC,
        self::TYPE_RANGE_START,
        self::TYPE_RANGE_END,
    ];

    protected $table = 'objection_chainages';

    protected $fillable = [
        'objection_id',
        'chainage',
        'chainage_meters',
        'entry_type',
    ];

    protected $casts = [
        'chainage_meters' => 'integer',
    ];

    // ==================== Relationships ====================

    /**
     * Get the objection this chainage belongs to.
     */
    public function objection(): BelongsTo
    {
        return $this->belongsTo(RfiObjection::class, 'objection_id');
    }

    // ==================== Accessors ====================

    /**
     * Get normalized chainage display format.
     */
    public function getNormalizedChainageAttribute(): ?string
    {
        return $this->normalizeChainageFormat($this->chainage);
    }

    // ==================== Static Helpers ====================

    /**
     * Create a chainage entry from a chainage string.
     *
     * @param  int  $objectionId  The objection ID
     * @param  string  $chainage  The chainage string
     * @param  string  $entryType  The entry type
     * @return static|null
     */
    public static function createFromString(int $objectionId, string $chainage, string $entryType = self::TYPE_SPECIFIC): ?self
    {
        $instance = new self;
        $meters = $instance->parseChainageToMeters($chainage);

        if ($meters === null) {
            return null;
        }

        return self::create([
            'objection_id' => $objectionId,
            'chainage' => trim($chainage),
            'chainage_meters' => $meters,
            'entry_type' => $entryType,
        ]);
    }

    /**
     * Create multiple specific chainage entries from a comma-separated string.
     *
     * @param  int  $objectionId  The objection ID
     * @param  string  $chainages  Comma-separated chainages
     * @return array<self> Created entries
     */
    public static function createMultipleSpecific(int $objectionId, string $chainages): array
    {
        $created = [];
        $instance = new self;
        $parts = preg_split('/\s*,\s*/', trim($chainages));

        foreach ($parts as $part) {
            $part = trim($part);
            if (empty($part)) {
                continue;
            }

            $entry = self::createFromString($objectionId, $part, self::TYPE_SPECIFIC);
            if ($entry) {
                $created[] = $entry;
            }
        }

        return $created;
    }

    /**
     * Create range entries (start and end) for an objection.
     *
     * @param  int  $objectionId  The objection ID
     * @param  string  $chainageFrom  Range start chainage
     * @param  string  $chainageTo  Range end chainage
     * @return array{start: self|null, end: self|null}
     */
    public static function createRange(int $objectionId, string $chainageFrom, string $chainageTo): array
    {
        $result = ['start' => null, 'end' => null];

        $startEntry = self::createFromString($objectionId, $chainageFrom, self::TYPE_RANGE_START);
        $endEntry = self::createFromString($objectionId, $chainageTo, self::TYPE_RANGE_END);

        if ($startEntry) {
            $result['start'] = $startEntry;
        }
        if ($endEntry) {
            $result['end'] = $endEntry;
        }

        return $result;
    }

    // ==================== Query Scopes ====================

    /**
     * Scope to get only specific chainages.
     */
    public function scopeSpecific($query)
    {
        return $query->where('entry_type', self::TYPE_SPECIFIC);
    }

    /**
     * Scope to get only range entries.
     */
    public function scopeRangeEntries($query)
    {
        return $query->whereIn('entry_type', [self::TYPE_RANGE_START, self::TYPE_RANGE_END]);
    }
}
