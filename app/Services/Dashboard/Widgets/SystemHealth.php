<?php

namespace App\Services\Dashboard\Widgets;

use App\Models\User;
use App\Services\Dashboard\DashboardWidget;
use Illuminate\Support\Facades\DB;

/**
 * System health for administrators: failed jobs, unresolved client errors, request volume
 * and server errors in the last 24 hours. Permission: system.monitoring.view (held by
 * Super Administrator). System-wide by nature, so it is governed by the permission alone.
 * Four indexed counts (request_logs.created_at, client_error_logs.last_seen_at).
 */
final class SystemHealth extends DashboardWidget
{
    public function key(): string
    {
        return 'admin.system_health';
    }

    public function title(): string
    {
        return 'System health';
    }

    public function permissions(): array
    {
        return ['system.monitoring.view'];
    }

    public function personas(): array
    {
        return ['administrator'];
    }

    public function section(): string
    {
        return self::SECTION_ADMIN;
    }

    public function dashboard(): string
    {
        return self::DASHBOARD_MAIN;
    }

    public function priority(): int
    {
        return 90;
    }

    public function span(): int
    {
        return 12;
    }

    public function ttl(): int
    {
        return 60;
    }

    public function type(): string
    {
        return 'analytics';
    }

    /** Days shown on the traffic charts. */
    private const DAYS = 14;

    public function data(User $viewer): array
    {
        $since = now()->subDay();

        $failedJobs = DB::table('failed_jobs')->count();
        $clientErrors = DB::table('client_error_logs')->whereNull('resolved_at')->where('last_seen_at', '>=', $since)->count();
        $requests = DB::table('request_logs')->where('created_at', '>=', $since)
            ->selectRaw('COUNT(*) as n, SUM(response_status >= 500) as errors')->first();
        $serverErrors = (int) ($requests->errors ?? 0);
        $daily = $this->daily();
        $errorsTail = $daily['errors'];

        return [
            'scope' => ['kind' => 'system', 'label' => 'Last 24 hours'],
            'total' => $failedJobs + $clientErrors + $serverErrors,
            'stats' => [
                $this->stat('failed_jobs', 'Failed jobs', $failedJobs, $this->toneFor($failedJobs, 'crit')),
                $this->stat('client_errors', 'Client errors (open)', $clientErrors, $this->toneFor($clientErrors)),
                $this->stat('server_errors', 'Server errors (5xx)', $serverErrors, $this->toneFor($serverErrors, 'crit')),
                $this->stat('requests', 'Requests', (int) ($requests->n ?? 0), 'neutral'),
            ],
            'kpis' => [
                $this->kpi('server_errors', 'Server errors (24h)', $serverErrors, $this->toneFor($serverErrors, 'crit'), null,
                    null,
                    array_sum($errorsTail) > 0 ? $errorsTail : null, 'Server errors per day, last '.self::DAYS.' days',
                    $failedJobs > 0 ? $failedJobs.' failed jobs' : null),
            ],
            'charts' => [
                $this->chart('system_errors', 'bar', 'Server errors per day', 'HTTP 5xx responses per day, last '.self::DAYS.' days',
                    ['categories' => $daily['labels'], 'series' => [['name' => 'Server errors (5xx)', 'tone' => 'crit', 'data' => $daily['errors']]]],
                    $daily['period'], 'No server errors in this period.'),
                $this->chart('system_requests', 'area', 'Request volume per day', 'Logged requests per day, last '.self::DAYS.' days',
                    ['categories' => $daily['labels'], 'series' => [['name' => 'Requests', 'tone' => 'theme', 'data' => $daily['requests']]]],
                    $daily['period'], 'No requests logged in this period.'),
            ],
        ];
    }

    /**
     * Requests and 5xx per day, oldest first (one grouped query on the indexed created_at).
     *
     * @return array{labels: array<int, string>, requests: array<int, int>, errors: array<int, int>, period: array{from: string, to: string}}
     */
    private function daily(): array
    {
        $from = now()->startOfDay()->subDays(self::DAYS - 1);
        $rows = DB::table('request_logs')->where('created_at', '>=', $from)
            ->selectRaw('DATE(created_at) as d, COUNT(*) as n, SUM(response_status >= 500) as e')->groupBy('d')->get()->keyBy('d');
        $days = array_map(fn ($i) => $from->copy()->addDays($i), range(0, self::DAYS - 1));

        return [
            'labels' => array_map(fn ($d) => $d->format('d M'), $days),
            'requests' => array_map(fn ($d) => (int) ($rows[$d->toDateString()]->n ?? 0), $days),
            'errors' => array_map(fn ($d) => (int) ($rows[$d->toDateString()]->e ?? 0), $days),
            'period' => ['from' => $from->toDateString(), 'to' => now()->toDateString()],
        ];
    }
}
