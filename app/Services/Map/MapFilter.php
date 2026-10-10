<?php

namespace App\Services\Map;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** The time window and search the map is asked for. The default is today (viewer-local app timezone). */
final class MapFilter
{
    /** @param array<int, string>|null $layers only these layer keys (null = all the viewer may see) */
    public function __construct(
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly ?string $search = null,
        public readonly ?array $layers = null,
        public readonly bool $explicit = false,
    ) {}

    public static function today(): self
    {
        $today = CarbonImmutable::today();

        return new self($today, $today);
    }

    public static function fromRequest(Request $request): self
    {
        $parse = function (?string $value): ?CarbonImmutable {
            if ($value === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) !== 1) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $value)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        $date = $parse($request->query('date'));
        $from = $parse($request->query('from')) ?? $date;
        $to = $parse($request->query('to')) ?? $date ?? $from;
        $explicit = $from !== null;
        $from ??= CarbonImmutable::today();
        $to ??= $from;
        if ($to->lt($from)) {
            [$from, $to] = [$to, $from];
        }
        // A range wider than a year is a mistake, not a question.
        if ($from->diffInDays($to) > 366) {
            $from = $to->subYear();
        }

        $search = trim((string) $request->query('q', ''));
        $layers = array_values(array_filter((array) $request->query('layers', []), 'is_string'));

        return new self($from, $to, $search === '' ? null : mb_substr($search, 0, 80), $layers === [] ? null : $layers, $explicit);
    }

    public function isSingleDay(): bool
    {
        return $this->from->isSameDay($this->to);
    }

    /** Stable cache fingerprint. */
    public function key(): string
    {
        return md5(json_encode([$this->from->toDateString(), $this->to->toDateString(), $this->search, $this->layers]));
    }

    /** @return array{from: string, to: string, search: ?string, single_day: bool} */
    public function toArray(): array
    {
        return ['from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'search' => $this->search, 'single_day' => $this->isSingleDay()];
    }
}
