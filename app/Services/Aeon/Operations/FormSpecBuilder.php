<?php

declare(strict_types=1);

namespace App\Services\Aeon\Operations;

use App\Models\User;
use App\Services\Aeon\Data\AeonAccess;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Transforms validated route rules + operation descriptors + natural user values
 * into complete Radix UI compatible form specifications for Generative UI.
 */
class FormSpecBuilder
{
    private const LONGTEXT = ['reason', 'description', 'notes', 'note', 'body', 'message', 'content', 'address', 'comment', 'comments', 'remarks', 'summary', 'defect_description'];

    public function __construct(private AeonAccess $access) {}

    /**
     * Build an interactive form specification.
     *
     * @param  array<string, array{required: bool, rules: array<int, string>}>  $rules
     * @param  array<string, mixed>  $op
     * @param  array<string, mixed>  $values
     * @return array<string, mixed>
     */
    public function build(array $rules, array $op, array $values = [], ?User $actor = null): array
    {
        $fields = [];
        foreach ($rules as $name => $spec) {
            $field = $this->field($name, $spec, $actor);
            if ($field === null) {
                continue;
            }
            $prefill = $this->prefill($name, $field, $values);
            if ($prefill !== null) {
                $field['value'] = $prefill;
            }
            $fields[] = $field;
        }

        $uri = $this->fillUri((string) $op['uri'], (array) ($op['params'] ?? []), $values);

        return [
            'type' => 'form',
            'entity' => $op['entity'] ?? 'record',
            'title' => $op['label'] ?? 'New record',
            'action' => $uri,
            'method' => strtolower((string) ($op['http'] ?? 'post')),
            'kind' => $op['kind'] ?? 'create',
            'submit_label' => $this->submitLabel((string) ($op['kind'] ?? 'create')),
            'note' => 'Runs the official Guardian endpoint — validation, permissions and audit apply.',
            'fields' => $fields,
        ];
    }

    private function field(string $name, array $spec, ?User $actor): ?array
    {
        $rules = $spec['rules'];
        $has = fn (string $r) => in_array($r, $rules, true);
        $ruleWith = function (string $prefix) use ($rules): ?string {
            foreach ($rules as $r) {
                if (Str::startsWith($r, $prefix)) {
                    return $r;
                }
            }

            return null;
        };

        $field = [
            'name' => $name,
            'label' => Str::headline(preg_replace('/_id$/', '', $name)),
            'required' => $spec['required'],
        ];

        if ($existsRule = $ruleWith('exists:')) {
            [$table, $col] = $this->parseExists($existsRule, $name);
            $field['type'] = 'select';
            $field['options'] = $this->optionsForTable($table, $col, $actor);

            return $field;
        }

        if ($inRule = $ruleWith('in:')) {
            $field['type'] = 'select';
            $field['options'] = array_map(
                static fn ($v) => ['value' => $v, 'label' => Str::headline((string) $v)],
                array_filter(explode(',', Str::after($inRule, 'in:')), static fn ($v) => $v !== '')
            );

            return $field;
        }

        if ($has('boolean')) {
            $field['type'] = 'toggle';

            return $field;
        }

        if ($has('date') || $ruleWith('date_format') || Str::endsWith($name, '_date') || in_array($name, ['date', 'punch_time'], true)) {
            $field['type'] = 'date';

            return $field;
        }

        if ($has('email') || str_contains($name, 'email')) {
            $field['type'] = 'email';

            return $field;
        }

        if ($has('integer') || $has('numeric') || in_array($name, ['amount', 'quantity', 'total', 'days', 'balance'], true)) {
            $field['type'] = 'number';

            return $field;
        }

        if ($has('array')) {
            return null;
        }

        $max = $ruleWith('max:');
        $maxlen = $max ? (int) Str::after($max, 'max:') : null;
        $isLong = in_array($name, self::LONGTEXT, true) || ($maxlen !== null && $maxlen > 255);

        $field['type'] = $isLong ? 'textarea' : 'text';
        if ($maxlen) {
            $field['maxlength'] = $maxlen;
        }

        return $field;
    }

    /**
     * Select options come only from tables the actor may query, narrowed to their rows and columns;
     * anything else yields no options (the submit endpoint still validates the value).
     */
    private function optionsForTable(string $table, string $valueCol, ?User $actor): array
    {
        try {
            $resolved = $actor ? $this->access->resolveTable($actor, $table) : null;
            if ($resolved === null) {
                return [];
            }

            $cols = $this->access->columns($actor, $resolved);
            if (! in_array($valueCol, $cols, true)) {
                return [];
            }

            $labelCol = null;
            foreach (['name', 'title', 'display_name', 'label', 'code', 'reference', 'number', 'subject'] as $c) {
                if (in_array($c, $cols, true)) {
                    $labelCol = $c;
                    break;
                }
            }

            $q = $this->access->scoped(DB::table($resolved), $actor, $resolved);
            if (in_array('deleted_at', $cols, true)) {
                $q->whereNull($resolved.'.deleted_at');
            }

            $select = [$resolved.'.'.$valueCol.' as v'];
            if ($labelCol) {
                $select[] = $resolved.'.'.$labelCol.' as l';
            }
            $rows = $q->select($select)->orderBy($resolved.'.'.($labelCol ?? $valueCol))->limit(200)->get();

            $out = [];
            foreach ($rows as $r) {
                $out[] = ['value' => $r->v, 'label' => (string) ($r->l ?? ('#'.$r->v))];
            }

            return $out;
        } catch (\Throwable) {
            return [];
        }
    }

    private function prefill(string $name, array $field, array $values): mixed
    {
        $raw = $this->matchValue($name, $values);
        if ($raw === null || $raw === '') {
            return null;
        }

        if (($field['type'] ?? '') === 'select' && ! empty($field['options'])) {
            foreach ($field['options'] as $o) {
                if ((string) $o['value'] === (string) $raw) {
                    return $o['value'];
                }
            }
            $needle = Str::lower((string) $raw);
            foreach ($field['options'] as $o) {
                if (str_contains(Str::lower($o['label']), $needle)) {
                    return $o['value'];
                }
            }

            return null;
        }

        if (($field['type'] ?? '') === 'toggle') {
            return in_array(Str::lower((string) $raw), ['1', 'true', 'yes', 'on'], true);
        }

        return $raw;
    }

    private function matchValue(string $name, array $values): mixed
    {
        if (array_key_exists($name, $values)) {
            return $values[$name];
        }

        $bare = preg_replace('/_id$/', '', $name);
        if ($bare !== $name && array_key_exists($bare, $values)) {
            return $values[$bare];
        }

        $aliases = [
            'start_date' => ['start', 'from', 'from_date', 'begin', 'date'],
            'end_date' => ['end', 'to', 'to_date', 'until'],
            'employee_id' => ['employee', 'person', 'staff', 'user', 'name'],
            'department_id' => ['department', 'dept'],
            'severity' => ['level', 'severity_level', 'priority'],
            'amount' => ['cost', 'price', 'total', 'money'],
        ];

        foreach ($aliases[$name] ?? [] as $a) {
            if (array_key_exists($a, $values)) {
                return $values[$a];
            }
        }

        return null;
    }

    private function parseExists(string $rule, string $field): array
    {
        $spec = Str::after($rule, 'exists:');
        $parts = explode(',', $spec);
        $table = trim($parts[0]);
        if (str_contains($table, '.')) {
            $table = Str::afterLast($table, '.');
        }
        $col = isset($parts[1]) && trim($parts[1]) !== '' ? trim($parts[1]) : 'id';

        return [$table, $col];
    }

    private function fillUri(string $uri, array $params, array $values): string
    {
        foreach ($params as $p) {
            $val = $values[$p] ?? $values[$p.'_id'] ?? $values['id'] ?? null;
            $uri = preg_replace('/\{'.preg_quote($p, '/').'\??\}/', (string) ($val ?? ''), $uri);
        }

        return $uri;
    }

    private function submitLabel(string $kind): string
    {
        return match ($kind) {
            'update' => 'Save changes',
            'delete' => 'Confirm delete',
            'action' => 'Confirm',
            default => 'Submit Record',
        };
    }
}
