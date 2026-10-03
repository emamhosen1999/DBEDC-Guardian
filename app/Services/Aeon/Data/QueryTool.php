<?php

declare(strict_types=1);

namespace App\Services\Aeon\Data;

use App\Contracts\Ai\AeonToolContract;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

/**
 * Dynamic, schema-aware data querying tool for DBEDC Guardian.
 * Produces deterministic numbers and generative-UI blocks (stats, charts, donuts, tables, entity cards).
 *
 * Every read is authorised by AeonAccess: only registered tables, only with the module's permission,
 * only the rows and columns the signed-in user may see. Anything else is reported as unknown.
 */
class QueryTool implements AeonToolContract
{
    public function __construct(
        private SchemaCatalog $schema,
        private AeonAccess $access
    ) {}

    public function name(): string
    {
        return 'query_data';
    }

    public function description(): string
    {
        return 'Query live database records in DBEDC Guardian. Supports count, aggregate (sum/avg), list, group-by breakdowns (donut/bar), time trends (sparklines), and entity details.';
    }

    public function parameters(): array
    {
        return [
            'entity' => [
                'type' => 'string',
                'description' => 'Target table, exactly as named in the QUERYABLE DATA list of the system prompt',
            ],
            'operation' => [
                'type' => 'string',
                'description' => 'Query operation: "count", "aggregate", "list", "group", "trend", or "find"',
                'enum' => ['count', 'aggregate', 'list', 'group', 'trend', 'find'],
            ],
            'filters' => [
                'type' => 'object',
                'description' => 'Key-value filter criteria on columns of that table (e.g. {"status": "pending"})',
            ],
            'group_by' => [
                'type' => 'string',
                'description' => 'Column to group by for breakdown charts (e.g. "status", "department_id", "severity")',
            ],
            'aggregate_column' => [
                'type' => 'string',
                'description' => 'Numeric column for sum/avg operations (e.g. "amount", "days", "balance")',
            ],
            'aggregate_type' => [
                'type' => 'string',
                'enum' => ['sum', 'avg', 'min', 'max'],
                'description' => 'Aggregation function',
            ],
            'order_by' => [
                'type' => 'string',
                'description' => 'Column to order by (e.g. "id", "created_at")',
            ],
            'order_dir' => [
                'type' => 'string',
                'enum' => ['asc', 'desc'],
                'description' => 'Order direction',
            ],
            'limit' => [
                'type' => 'integer',
                'description' => 'Maximum records to return (default 10, max 50)',
            ],
            'id' => [
                'type' => 'integer',
                'description' => 'Entity ID for "find" operation',
            ],
        ];
    }

    public function run(array $args, int|string|null $userId): array
    {
        $entity = (string) ($args['entity'] ?? '');
        $actor = $this->access->actor($userId);
        $table = $actor ? $this->access->resolveTable($actor, $entity) : null;
        $meta = $table ? $this->access->entity($actor, $table) : null;

        if (! $table || ! $meta) {
            // Identical answer for "does not exist" and "not yours to see": never confirm a table exists.
            return [
                'text' => "Table or entity '{$entity}' was not found in Guardian schema.",
                'blocks' => [],
                'data' => ['error' => 'unknown_table', 'entity' => $entity],
            ];
        }

        $op = (string) ($args['operation'] ?? 'count');
        $filters = (array) ($args['filters'] ?? []);
        $limit = min(50, max(1, (int) ($args['limit'] ?? 10)));

        try {
            $qb = DB::table($table);
            $qb = $this->access->scoped($qb, $actor, $table);
            $this->applyFilters($qb, $table, $filters, $meta['columns']);

            return match ($op) {
                'count' => $this->handleCount($qb, $table, $meta['label'] ?? $table),
                'aggregate' => $this->handleAggregate($qb, $table, $args, $meta),
                'group' => $this->handleGroup($qb, $table, $args, $meta),
                'trend' => $this->handleTrend($qb, $table, $meta),
                'find' => $this->handleFind($qb, $table, $args, $meta),
                default => $this->handleList($qb, $table, $args, $meta, $limit),
            };
        } catch (\Throwable $e) {
            Log::warning('Aeon data query failed', ['table' => $table, 'op' => $op, 'error' => $e->getMessage()]);

            return [
                'text' => 'Data query failed.',
                'blocks' => [],
                'data' => ['error' => 'query_failed'],
            ];
        }
    }

    private function handleCount($qb, string $table, string $label): array
    {
        $count = $qb->count();

        return [
            'text' => "Found {$count} {$label} record(s).",
            'blocks' => [
                [
                    'type' => 'stats',
                    'items' => [
                        [
                            'k' => "Total {$label}",
                            'v' => number_format($count),
                        ],
                    ],
                ],
            ],
            'data' => ['table' => $table, 'count' => $count],
        ];
    }

    private function handleAggregate($qb, string $table, array $args, array $meta): array
    {
        $col = (string) ($args['aggregate_column'] ?? 'amount');
        if (! in_array($col, $meta['columns'], true)) {
            return $this->handleCount($qb, $table, $meta['label']);
        }

        $type = strtolower((string) ($args['aggregate_type'] ?? 'sum'));
        $val = match ($type) {
            'avg' => (float) ($qb->avg($col) ?? 0),
            'min' => (float) ($qb->min($col) ?? 0),
            'max' => (float) ($qb->max($col) ?? 0),
            default => (float) ($qb->sum($col) ?? 0),
        };

        $formatted = is_float($val) && floor($val) != $val ? number_format($val, 2) : number_format($val);

        return [
            'text' => strtoupper($type)." of {$col} on {$meta['label']} is {$formatted}.",
            'blocks' => [
                [
                    'type' => 'stats',
                    'items' => [
                        [
                            'k' => strtoupper($type)." {$col}",
                            'v' => $formatted,
                        ],
                    ],
                ],
            ],
            'data' => ['table' => $table, 'column' => $col, 'aggregate' => $type, 'value' => $val],
        ];
    }

    private function handleGroup($qb, string $table, array $args, array $meta): array
    {
        $groupCol = (string) ($args['group_by'] ?? 'status');
        if (! in_array($groupCol, $meta['columns'], true)) {
            $groupCol = in_array('status', $meta['columns'], true) ? 'status' : $meta['columns'][0];
        }

        $rows = $qb->select($groupCol, DB::raw('count(*) as count'))
            ->groupBy($groupCol)
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        $items = [];
        foreach ($rows as $r) {
            $key = $r->{$groupCol};
            $label = $key === null ? 'Unspecified' : Str::headline((string) $key);
            $items[] = [
                'label' => $label,
                'value' => (int) $r->count,
            ];
        }

        $isStatus = str_contains($groupCol, 'status') || str_contains($groupCol, 'type') || str_contains($groupCol, 'severity');

        return [
            'text' => "Breakdown of {$meta['label']} by {$groupCol}.",
            'blocks' => [
                [
                    'type' => $isStatus ? 'donut' : 'bar',
                    'title' => "{$meta['label']} by ".Str::headline($groupCol),
                    'items' => $items,
                ],
            ],
            'data' => ['table' => $table, 'breakdown' => $items],
        ];
    }

    private function handleTrend($qb, string $table, array $meta): array
    {
        $dateCol = $meta['date_columns'][0] ?? null;
        if ($dateCol === null) {
            return $this->handleCount($qb, $table, $meta['label']);
        }

        $rows = $qb->select(DB::raw("DATE({$dateCol}) as d"), DB::raw('count(*) as c'))
            ->whereNotNull($dateCol)
            ->groupBy('d')
            ->orderBy('d', 'asc')
            ->limit(14)
            ->get();

        $points = [];
        $total = 0;
        foreach ($rows as $r) {
            $c = (int) $r->c;
            $points[] = $c;
            $total += $c;
        }

        return [
            'text' => "Recent activity trend for {$meta['label']} ({$total} total over period).",
            'blocks' => [
                [
                    'type' => 'chart',
                    'title' => "{$meta['label']} Trend",
                    'value' => number_format($total),
                    'points' => $points,
                ],
            ],
            'data' => ['table' => $table, 'points' => $points, 'total' => $total],
        ];
    }

    private function handleFind($qb, string $table, array $args, array $meta): array
    {
        $id = $args['id'] ?? null;
        if (! $id) {
            return ['text' => 'Entity ID required for find operation.', 'blocks' => [], 'data' => []];
        }

        $keyCol = in_array('id', $meta['columns'], true) ? 'id' : ($meta['columns'][0] ?? 'id');
        $row = $qb->select($meta['columns'])->where($table.'.'.$keyCol, $id)->first();
        if (! $row) {
            return ['text' => "Record #{$id} not found in {$meta['label']}.", 'blocks' => [], 'data' => []];
        }

        $title = (string) ($row->name ?? $row->title ?? $row->code ?? $row->reference ?? "#{$id}");
        $subtitle = (string) ($row->status ?? $row->type ?? $meta['label']);

        $fields = [];
        foreach ((array) $row as $k => $v) {
            if (in_array($k, ['id', 'name', 'title'], true) || $v === null) {
                continue;
            }
            $fields[] = [
                'k' => Str::headline($k),
                'v' => is_string($v) && strlen($v) > 60 ? substr($v, 0, 60).'...' : (string) $v,
            ];
            if (count($fields) >= 6) {
                break;
            }
        }

        return [
            'text' => "Details for {$title} in {$meta['label']}.",
            'blocks' => [
                [
                    'type' => 'entityCard',
                    'title' => $title,
                    'subtitle' => Str::headline($subtitle),
                    'fields' => $fields,
                ],
            ],
            'data' => (array) $row,
        ];
    }

    private function handleList($qb, string $table, array $args, array $meta, int $limit): array
    {
        $orderCol = (string) ($args['order_by'] ?? 'id');
        if (! in_array($orderCol, $meta['columns'], true)) {
            $orderCol = in_array('id', $meta['columns'], true) ? 'id' : $meta['columns'][0];
        }
        $orderDir = strtolower((string) ($args['order_dir'] ?? 'desc')) === 'asc' ? 'asc' : 'desc';

        $columns = array_slice($meta['columns'], 0, 6);
        $rows = $qb->select($columns)->orderBy($orderCol, $orderDir)->limit($limit)->get();

        $tableRows = [];
        foreach ($rows as $r) {
            $rowItem = [];
            foreach ($columns as $c) {
                $v = $r->{$c};
                $rowItem[] = is_string($v) && strlen($v) > 35 ? substr($v, 0, 35).'...' : (string) ($v ?? '-');
            }
            $tableRows[] = $rowItem;
        }

        return [
            'text' => "Showing {$rows->count()} records from {$meta['label']}.",
            'blocks' => [
                [
                    'type' => 'table',
                    'columns' => array_map(fn ($c) => Str::headline($c), $columns),
                    'rows' => $tableRows,
                ],
            ],
            'data' => ['table' => $table, 'count' => $rows->count(), 'records' => $rows->toArray()],
        ];
    }

    private function applyFilters($qb, string $table, array $filters, array $allowedCols): void
    {
        foreach ($filters as $col => $val) {
            if (! in_array($col, $allowedCols, true) || $val === null || $val === '') {
                continue;
            }

            if (is_array($val)) {
                $qb->whereIn($table.'.'.$col, $val);
            } elseif (is_string($val) && str_contains($val, '%')) {
                $qb->where($table.'.'.$col, 'like', $val);
            } else {
                $qb->where($table.'.'.$col, $val);
            }
        }
    }
}
