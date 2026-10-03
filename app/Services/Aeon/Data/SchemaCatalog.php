<?php

declare(strict_types=1);

namespace App\Services\Aeon\Data;

use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Discovers live database tables and columns for Aeon's dynamic query engine.
 * Protects sensitive columns (passwords, tokens, keys) and caches table schema.
 */
class SchemaCatalog
{
    /** Column names never exposed to AI querying or block output */
    public const SENSITIVE_COLUMNS = [
        'password',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'api_token',
        'token',
        'secret',
        'private_key',
        'refresh_token',
        'firebase_token',
        'fcm_token',
        'device_token',
        'card_number',
        'cvv',
    ];

    /**
     * Identity-document and bank columns (matched on whole underscore-separated words): NEVER returned
     * through Aeon, whatever the actor's permissions.
     */
    private const IDENTITY_PATTERN = '/(^|_)(nid|passport|bank|account|ifsc|pan|pf|esi|tin|ssn|iban|swift|routing|national|nationality)(_|$)/';

    /**
     * Compensation columns: returned (and aggregated, grouped, filtered on) only for an actor holding
     * employees.compensation.view with scope over the row.
     */
    private const COMPENSATION_PATTERN = '/(^|_)(salary|wages?|payroll|gross|compensation|bonus|gratuity|earnings?|deductions?|ctc|payable|encashment)(_|$)|^(daily_rate|net_pay|basic_pay)$/';

    /** System/framework tables hidden from AI querying */
    public const IGNORED_TABLES = [
        'migrations',
        'failed_jobs',
        'password_reset_tokens',
        'password_resets',
        'personal_access_tokens',
        'sessions',
        'job_batches',
        'jobs',
        'cache',
        'cache_locks',
        'aeon_embeddings',
    ];

    /** @var array<string, array{name: string, label: string, columns: array<int, string>, date_columns: array<int, string>, numeric_columns: array<int, string>, fk_columns: array<int, string>}>|null */
    private ?array $catalog = null;

    /**
     * Get the full catalog of queryable database tables.
     *
     * @return array<string, array{name: string, label: string, columns: array<int, string>, date_columns: array<int, string>, numeric_columns: array<int, string>, fk_columns: array<int, string>}>
     */
    public function all(): array
    {
        if ($this->catalog !== null) {
            return $this->catalog;
        }

        return $this->catalog = Cache::remember('aeon_schema_catalog_v3', 3600, function () {
            $tables = [];
            try {
                $dbTables = Schema::getTableListing();
                foreach ($dbTables as $table) {
                    if (in_array($table, self::IGNORED_TABLES, true)) {
                        continue;
                    }

                    $columns = Schema::getColumnListing($table);
                    $cleanCols = array_values(array_filter($columns, fn ($c) => ! $this->isSensitive($c)));

                    if (empty($cleanCols)) {
                        continue;
                    }

                    $dateCols = [];
                    $numCols = [];
                    $fkCols = [];

                    foreach ($cleanCols as $col) {
                        if (Str::endsWith($col, ['_at', '_date']) || in_array($col, ['date', 'punch_time', 'logged_at', 'created_at'], true)) {
                            $dateCols[] = $col;
                        }
                        if (Str::endsWith($col, '_id')) {
                            $fkCols[] = $col;
                        }
                        if (in_array($col, ['amount', 'quantity', 'total', 'price', 'rate', 'balance', 'count', 'days', 'hours', 'score', 'severity', 'priority'], true)) {
                            $numCols[] = $col;
                        }
                    }

                    $tables[$table] = [
                        'name' => $table,
                        'label' => Str::headline($table),
                        'columns' => $cleanCols,
                        'date_columns' => $dateCols,
                        'numeric_columns' => $numCols,
                        'fk_columns' => $fkCols,
                    ];
                }
            } catch (\Throwable) {
                // Return empty if schema query fails
            }

            return $tables;
        });
    }

    /**
     * Resolve a table name from user input or entity alias.
     *
     * UNGATED: knows every table. Used only by the write-form builders, whose routes are
     * permission-checked separately. Data reads MUST go through AeonAccess instead.
     */
    public function resolveTable(string $name): ?string
    {
        $name = strtolower(trim($name));
        $all = $this->all();

        if (isset($all[$name])) {
            return $name;
        }

        $plural = Str::plural($name);
        if (isset($all[$plural])) {
            return $plural;
        }

        $snake = Str::snake($name);
        if (isset($all[$snake])) {
            return $snake;
        }

        $snakePlural = Str::plural($snake);
        if (isset($all[$snakePlural])) {
            return $snakePlural;
        }

        return null;
    }

    /**
     * Retrieve metadata for a specific table.
     *
     * @return array{name: string, label: string, columns: array<int, string>, date_columns: array<int, string>, numeric_columns: array<int, string>, fk_columns: array<int, string>}|null
     */
    public function entity(string $table): ?array
    {
        $resolved = $this->resolveTable($table);
        if (! $resolved) {
            return null;
        }

        return $this->all()[$resolved] ?? null;
    }

    /**
     * Check if a column name is sensitive.
     */
    public function isSensitive(string $column): bool
    {
        $col = strtolower(trim($column));
        if (preg_match(self::IDENTITY_PATTERN, $col) === 1) {
            return true;
        }
        foreach (self::SENSITIVE_COLUMNS as $sensitive) {
            if ($col === $sensitive || str_contains($col, $sensitive)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Is this a salary / pay / statutory-deduction column (gated on employees.compensation.view)?
     */
    public function isCompensation(string $column): bool
    {
        return preg_match(self::COMPENSATION_PATTERN, strtolower(trim($column))) === 1;
    }
}
