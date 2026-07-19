<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Schema;

/**
 * Read-only drift detector for the indexes the application depends on.
 *
 * Motivation: a migration can be recorded as "Ran" while having created none of
 * the indexes it declares (see 2026_06_02_120000_add_indexes_for_scalability),
 * which leaves environments silently divergent — a query that is index-backed on
 * production degrades to a full table scan on another box with no error anywhere.
 * This command turns that invisible drift into a one-line, prod-safe check.
 *
 * It ONLY reads the information schema (Schema::getIndexes / hasTable /
 * hasColumns). It creates, alters and drops nothing, so it is safe to run on any
 * environment including production. Exit code is 1 when something the app relies
 * on is missing, 0 when the schema is fully covered — usable as a CI / deploy gate.
 */
class VerifySchemaIndexes extends Command
{
    protected $signature = 'schema:verify-indexes {--json : Output the report as JSON}';

    protected $description = 'Verify (read-only) that the indexes the app depends on exist on the current connection';

    /**
     * The index coverage the application depends on, expressed by COLUMN LIST
     * rather than by index name — two migrations legitimately create equivalent
     * indexes under different names, and MySQL uses an index by its leading
     * columns, not its name. A requirement is satisfied when some index on the
     * table has the required columns as a leading prefix.
     *
     * @var array<int, array{table: string, columns: array<int, string>, why: string}>
     */
    private array $required = [
        ['table' => 'attendances',          'columns' => ['user_id', 'date'],               'why' => 'per-user attendance lookups by date'],
        ['table' => 'daily_works',          'columns' => ['incharge', 'date'],              'why' => 'daily-works list filtered by incharge, sorted by date'],
        ['table' => 'daily_works',          'columns' => ['assigned', 'date'],              'why' => 'daily-works assigned arm of the visibility filter'],
        ['table' => 'daily_works',          'columns' => ['date'],                          'why' => 'unscoped daily-works ORDER BY date / date-range scans'],
        ['table' => 'daily_works',          'columns' => ['updated_at'],                    'why' => 'mobile delta-sync cursor scan'],
        ['table' => 'daily_work_objection', 'columns' => ['daily_work_id', 'rfi_objection_id'], 'why' => 'withCount(activeObjections) pivot join'],
        ['table' => 'daily_work_objection', 'columns' => ['rfi_objection_id'],              'why' => 'objection -> daily works reverse lookup'],
        ['table' => 'rfi_objections',       'columns' => ['status'],                        'why' => 'active-objection status filter'],
        ['table' => 'users',                'columns' => ['report_to'],                     'why' => 'reporting-tree walk (ResolvesTeamMembers)'],
        ['table' => 'leaves',               'columns' => ['user_id'],                       'why' => 'per-user leave lookups'],
        ['table' => 'leaves',               'columns' => ['user_id', 'from_date', 'to_date'], 'why' => 'leave date-range / overlap queries'],
        ['table' => 'leaves',               'columns' => ['user_id', 'updated_at', 'id'],   'why' => 'mobile leave sync cursor'],
        ['table' => 'petty_cash_loans',     'columns' => ['user_id'],                       'why' => 'per-user petty-cash loan lookups'],
        ['table' => 'sync_tombstones',      'columns' => ['user_id', 'module', 'id'],       'why' => 'delete tombstone pull cursor'],
        ['table' => 'attendances',          'columns' => ['updated_at'],                    'why' => 'attendance delta-sync cursor scan'],
    ];

    public function handle(): int
    {
        $rows = [];
        $missing = [];

        foreach ($this->required as $req) {
            [$table, $columns, $why] = [$req['table'], $req['columns'], $req['why']];
            $needle = implode(', ', $columns);

            if (! Schema::hasTable($table)) {
                $rows[] = [$table, $needle, 'TABLE MISSING', $why];
                $missing[] = $req + ['reason' => 'table missing'];

                continue;
            }

            if (! Schema::hasColumns($table, $columns)) {
                $rows[] = [$table, $needle, 'COLUMN MISSING', $why];
                $missing[] = $req + ['reason' => 'column missing'];

                continue;
            }

            $coveringIndex = $this->coveringIndex($table, $columns);

            if ($coveringIndex === null) {
                $rows[] = [$table, $needle, 'MISSING', $why];
                $missing[] = $req + ['reason' => 'no covering index'];
            } else {
                $rows[] = [$table, $needle, "ok ({$coveringIndex})", $why];
            }
        }

        if ($this->option('json')) {
            $this->line((string) json_encode([
                'connection' => Schema::getConnection()->getName(),
                'ok' => count($missing) === 0,
                'missing' => array_values($missing),
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return count($missing) === 0 ? self::SUCCESS : self::FAILURE;
        }

        $this->info('Schema index verification — connection: '.Schema::getConnection()->getName());
        $this->table(['Table', 'Required columns', 'Status', 'Serves'], $rows);

        if ($missing !== []) {
            $this->error(count($missing).' required index/coverage item(s) MISSING on this connection.');
            $this->line('This environment has drifted from the schema the app depends on.');

            return self::FAILURE;
        }

        $this->info('All '.count($this->required).' required indexes are present.');

        return self::SUCCESS;
    }

    /**
     * Return the name of an index whose leading columns match $columns exactly
     * (a prefix match, which is how MySQL can use it), or null if none covers it.
     *
     * @param  array<int, string>  $columns
     */
    private function coveringIndex(string $table, array $columns): ?string
    {
        $needle = array_map('strtolower', $columns);

        foreach (Schema::getIndexes($table) as $index) {
            $indexColumns = array_map('strtolower', $index['columns'] ?? []);
            if (array_slice($indexColumns, 0, count($needle)) === $needle) {
                return (string) $index['name'];
            }
        }

        return null;
    }
}
