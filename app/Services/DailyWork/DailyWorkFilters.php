<?php

namespace App\Services\DailyWork;

use App\Models\RfiObjection;
use App\Support\Corridor\Chainage;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Validation\ValidationException;

/**
 * The one description of "which daily works" used by the register, the export, the summary and the print, so the same
 * filter always means the same rows. Everything is an index-friendly predicate on real columns (jurisdiction_id is the
 * attribution as of the work's date, start_m / end_m the parsed chainage), never a string scan of the location.
 */
final class DailyWorkFilters
{
    public const OBJECTIONS = ['active', 'any', 'none'];

    /** data-quality flags: unplaced = the location could not be read as a chainage, unattributed = no section on the work's date */
    public const QUALITY = ['unplaced', 'unattributed'];

    /** @param array<int, string> $status */
    public function __construct(
        public readonly ?string $q = null,
        public readonly array $status = [],
        public readonly array $type = [],
        public readonly array $side = [],
        public readonly array $incharge = [],
        public readonly array $assigned = [],
        public readonly array $jurisdiction = [],
        public readonly ?string $dateFrom = null,
        public readonly ?string $dateTo = null,
        public readonly ?int $chainFrom = null,
        public readonly ?int $chainTo = null,
        public readonly ?string $objections = null,
        public readonly ?string $quality = null,
        public readonly bool $trashed = false,
        public readonly ?int $importBatch = null,
    ) {}

    /** @param array<string, mixed> $in */
    public static function fromArray(array $in): self
    {
        $list = static function (mixed $v): array {
            if (is_string($v)) {
                $v = explode(',', $v);
            }

            return array_values(array_unique(array_filter(array_map(fn ($x): string => trim((string) $x), (array) $v), fn (string $x): bool => $x !== '' && $x !== 'all')));
        };
        $metres = static function (mixed $v, string $field): ?int {
            if ($v === null || $v === '') {
                return null;
            }
            $m = is_numeric($v) ? (int) $v : Chainage::token((string) $v);
            if ($m === null || $m < 0 || $m > Chainage::MAX_M) {
                throw ValidationException::withMessages([$field => 'Enter a chainage such as K5+900 or a number of metres.']);
            }

            return $m;
        };
        $date = static fn (mixed $v): ?string => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) === 1 ? $v : null;
        $objections = in_array($in['objections'] ?? null, self::OBJECTIONS, true) ? $in['objections'] : null;
        $quality = in_array($in['quality'] ?? null, self::QUALITY, true) ? $in['quality'] : null;
        $q = isset($in['q']) ? trim((string) $in['q']) : (isset($in['search']) ? trim((string) $in['search']) : null);

        return new self(
            q: $q === '' ? null : mb_substr((string) $q, 0, 120),
            status: $list($in['status'] ?? []), type: $list($in['type'] ?? []), side: $list($in['side'] ?? []),
            incharge: $list($in['incharge'] ?? ($in['inCharge'] ?? [])), assigned: $list($in['assigned'] ?? []),
            jurisdiction: array_map('intval', $list($in['jurisdiction'] ?? [])),
            dateFrom: $date($in['date_from'] ?? ($in['startDate'] ?? null)), dateTo: $date($in['date_to'] ?? ($in['endDate'] ?? null)),
            chainFrom: $metres($in['chain_from'] ?? null, 'chain_from'), chainTo: $metres($in['chain_to'] ?? null, 'chain_to'),
            objections: $objections, quality: $quality, trashed: filter_var($in['trashed'] ?? false, FILTER_VALIDATE_BOOLEAN),
            importBatch: isset($in['import_batch']) && is_numeric($in['import_batch']) ? (int) $in['import_batch'] : null,
        );
    }

    public function apply(Builder $query): Builder
    {
        if ($this->trashed) {
            $query->onlyTrashed();
        }
        if ($this->dateFrom !== null) {
            $query->where('date', '>=', $this->dateFrom);
        }
        if ($this->dateTo !== null) {
            // "< the next day" keeps the predicate on the indexed column (no DATE() wrapper) and is right for a date or a datetime
            $query->where('date', '<', date('Y-m-d', strtotime($this->dateTo.' +1 day')));
        }
        if ($this->status !== []) {
            $query->whereIn('status', array_values(array_unique(array_merge(...array_map(fn (string $s): array => $s === 'completed' ? ['completed', 'complete'] : [$s], $this->status)))));
        }
        if ($this->type !== []) {
            $query->whereIn('type', $this->type);
        }
        if ($this->side !== []) {
            $query->whereIn('side', $this->side);
        }
        if ($this->incharge !== []) {
            $query->whereIn('incharge', $this->incharge);
        }
        if ($this->assigned !== []) {
            $query->whereIn('assigned', $this->assigned);
        }
        if ($this->jurisdiction !== []) {
            $query->whereIn('jurisdiction_id', $this->jurisdiction);
        }
        if ($this->chainFrom !== null || $this->chainTo !== null) {
            // a work's [start_m, end_m] overlaps the asked range
            $query->whereNotNull('start_m');
            if ($this->chainTo !== null) {
                $query->where('start_m', '<=', $this->chainTo);
            }
            if ($this->chainFrom !== null) {
                $query->where('end_m', '>=', $this->chainFrom);
            }
        }
        if ($this->quality === 'unplaced') {
            $query->whereNull('start_m');
        } elseif ($this->quality === 'unattributed') {
            $query->whereNull('jurisdiction_id');
        }
        if ($this->importBatch !== null) {
            $query->where('import_batch_id', $this->importBatch);
        }
        if ($this->objections === 'active') {
            $query->whereHas('objections', fn ($o) => $o->whereIn('rfi_objections.status', RfiObjection::$activeStatuses));
        } elseif ($this->objections === 'any') {
            $query->whereHas('objections');
        } elseif ($this->objections === 'none') {
            $query->whereDoesntHave('objections');
        }
        if ($this->q !== null) {
            foreach (array_filter(preg_split('/\s+/', $this->q)) as $word) {
                $like = '%'.$word.'%';
                $query->where(fn ($w) => $w->where('number', 'like', $like)->orWhere('location', 'like', $like)->orWhere('description', 'like', $like)
                    ->orWhere('type', 'like', $like)->orWhere('side', 'like', $like)->orWhere('inspection_details', 'like', $like));
            }
        }

        return $query;
    }

    /** @return array<string, mixed> the normalised filter, for echoing back and for export file names */
    public function toArray(): array
    {
        return array_filter([
            'q' => $this->q, 'status' => $this->status, 'type' => $this->type, 'side' => $this->side, 'incharge' => $this->incharge, 'assigned' => $this->assigned,
            'jurisdiction' => $this->jurisdiction, 'date_from' => $this->dateFrom, 'date_to' => $this->dateTo,
            'chain_from' => $this->chainFrom, 'chain_to' => $this->chainTo, 'objections' => $this->objections, 'quality' => $this->quality,
            'trashed' => $this->trashed ?: null, 'import_batch' => $this->importBatch,
        ], fn ($v): bool => $v !== null && $v !== []);
    }
}
