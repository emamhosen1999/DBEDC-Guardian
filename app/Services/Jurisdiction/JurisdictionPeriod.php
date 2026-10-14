<?php

namespace App\Services\Jurisdiction;

use Carbon\CarbonImmutable;
use Illuminate\Http\Request;

/** The reporting window of the jurisdiction stats: 7d / 30d / 90d / 365d / all, or an explicit from-to. Dates are Asia/Dhaka days. */
final class JurisdictionPeriod
{
    public const PRESETS = ['7d' => 7, '30d' => 30, '90d' => 90, '365d' => 365];

    public const DEFAULT = '30d';

    private function __construct(
        public readonly string $key,
        public readonly CarbonImmutable $from,
        public readonly CarbonImmutable $to,
        public readonly string $label,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return self::make($request->query('period'), $request->query('from'), $request->query('to'));
    }

    public static function make(?string $period, ?string $from = null, ?string $to = null): self
    {
        $today = CarbonImmutable::today(config('app.timezone'));
        $parse = static function (?string $v): ?CarbonImmutable {
            if ($v === null || preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) !== 1) {
                return null;
            }
            try {
                return CarbonImmutable::createFromFormat('Y-m-d', $v)->startOfDay();
            } catch (\Throwable) {
                return null;
            }
        };

        if ($period === 'custom' || ($period === null && ($from !== null || $to !== null))) {
            $f = $parse($from) ?? $today->subDays(29);
            $t = $parse($to) ?? $today;
            if ($t->lt($f)) {
                [$f, $t] = [$t, $f];
            }

            return new self('custom', $f, $t, $f->format('j M Y').' to '.$t->format('j M Y'));
        }
        if ($period === 'all') {
            return new self('all', CarbonImmutable::create(2000, 1, 1), $today, 'All time');
        }
        $key = array_key_exists((string) $period, self::PRESETS) ? (string) $period : self::DEFAULT;

        return new self($key, $today->subDays(self::PRESETS[$key] - 1), $today, 'Last '.self::PRESETS[$key].' days');
    }

    /** @return array{key: string, from: string, to: string, label: string} */
    public function toArray(): array
    {
        return ['key' => $this->key, 'from' => $this->from->toDateString(), 'to' => $this->to->toDateString(), 'label' => $this->label];
    }
}
