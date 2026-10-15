<?php

namespace App\Services\DailyWork;

use App\Models\DailyWork;
use App\Models\User;
use App\Services\Corridor\ChainageResolver;
use App\Services\Jurisdiction\JurisdictionTeamService;
use App\Support\Corridor\Chainage;
use Carbon\Carbon;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Reads the rows of an import file and says, per row and BEFORE anything is written, what would happen to it:
 * valid, warning or error, with every reason. The preview shows this; the commit applies exactly this (it re-runs the
 * analysis on the stored file, never on what the browser sends back), so what was previewed is what is committed.
 *
 * Actions: create (a new RFI), resubmit (the RFI exists: its next numbered revision), skip (nothing to do - already
 * completed, or already imported for this date, which makes re-importing a file harmless), reject (an error).
 */
class DailyWorkImportAnalyzer
{
    public const ERROR = 'error';

    public const WARNING = 'warning';

    public const VALID = 'valid';

    public function __construct(private readonly ChainageResolver $resolver, private readonly JurisdictionTeamService $team) {}

    /**
     * @param  array<int, array<int, array<int, mixed>>>  $sheets  Excel::toArray()
     * @return array{sheets: array<int, array<string, mixed>>, counts: array<string, int>}
     */
    public function analyze(array $sheets): array
    {
        $out = [];
        $counts = ['rows' => 0, 'valid' => 0, 'warning' => 0, 'error' => 0, 'create' => 0, 'resubmit' => 0, 'skip' => 0, 'reject' => 0];
        $seen = []; // RFI number => ['date' => ..., 'sheet' => n, 'row' => n] for the rows analysed so far
        $names = [];

        foreach ($sheets as $sheetIndex => $rows) {
            if (empty($rows)) {
                continue;
            }
            $date = $this->date($rows[0][0] ?? null);
            $sheetRows = [];
            foreach ($rows as $rowIndex => $cells) {
                $row = $this->row($sheetIndex + 1, $rowIndex + 1, (array) $cells, $date, $seen);
                if ($row['incharge'] !== null) {
                    $names[$row['incharge']['id']] ??= User::query()->whereKey($row['incharge']['id'])->value('name');
                    $row['incharge']['name'] = $names[$row['incharge']['id']];
                }
                $sheetRows[] = $row;
                $counts['rows']++;
                $counts[$row['level']]++;
                $counts[$row['action']]++;
            }
            $out[] = ['sheet' => $sheetIndex + 1, 'date' => $date, 'rows' => $sheetRows];
        }

        return ['sheets' => $out, 'counts' => $counts];
    }

    /** @param array<string, array<string, mixed>> $seen */
    private function row(int $sheet, int $index, array $cells, ?string $sheetDate, array &$seen): array
    {
        $issues = [];
        $add = function (string $level, string $field, string $message) use (&$issues): void {
            $issues[] = ['level' => $level, 'field' => $field, 'message' => $message];
        };
        $text = static fn (mixed $v): string => trim((string) ($v ?? ''));

        $rowDate = $this->date($cells[0] ?? null);
        $number = $text($cells[1] ?? null);
        $typeRaw = $text($cells[2] ?? null);
        $description = $text($cells[3] ?? null);
        $location = $text($cells[4] ?? null);
        $side = $text($cells[5] ?? null);

        if ($sheetDate === null) {
            $add(self::ERROR, 'date', 'The sheet has no readable date in its first cell.');
        }
        if ($rowDate === null) {
            $add(self::ERROR, 'date', 'This row has no readable date (use YYYY-MM-DD).');
        } elseif ($sheetDate !== null && $rowDate !== $sheetDate) {
            $add(self::ERROR, 'date', "The date {$rowDate} does not match the sheet's date {$sheetDate}.");
        }
        if ($number === '') {
            $add(self::ERROR, 'rfi_number', 'The RFI number is missing.');
        }
        $type = $this->type($typeRaw);
        if ($typeRaw === '') {
            $add(self::ERROR, 'type', 'The work type is missing.');
        } elseif ($type === null) {
            $add(self::ERROR, 'type', "Unknown work type \"{$typeRaw}\". Use Embankment, Structure or Pavement.");
        } elseif ($type !== $typeRaw) {
            $add(self::WARNING, 'type', "Work type read as {$type}.");
        }
        if ($description === '') {
            $add(self::ERROR, 'description', 'The description is missing.');
        }
        if ($side !== '' && ! in_array($side, DailyWork::$sides, true)) {
            $add(self::WARNING, 'side', "Road side \"{$side}\" is not one of ".implode(', ', DailyWork::$sides).'; it is kept as written.');
        }

        // Chainage: parsed by the one chainage service, resolved to the section ON THE WORK'S DATE.
        $span = $location === '' ? null : $this->resolver->span($location);
        $jurisdiction = null;
        $incharge = null;
        if ($location === '') {
            $add(self::ERROR, 'location', 'The location is missing.');
        } elseif ($span === null) {
            $add(self::ERROR, 'location', "\"{$location}\" is not a readable chainage. Write it like K5+900 or K05+900-K06+400.");
        } else {
            if (Chainage::span($location) === null) {
                $add(self::WARNING, 'location', 'Only a kilometre was given: it is read as '.Chainage::format($span['from']).'.');
            }
            $j = $rowDate === null ? null : $this->resolver->forSpan($span['from'], $span['to'], $rowDate);
            if ($rowDate !== null && $j === null) {
                $add(self::ERROR, 'location', 'No jurisdiction covers '.Chainage::format($span['from']).' on '.$rowDate.'.');
            } elseif ($j !== null) {
                $jurisdiction = ['id' => $j->id, 'code' => $j->code, 'name' => $j->location];
                $id = $this->team->inChargeAsOf($j->id, $rowDate) ?? (string) $j->incharge;
                $incharge = $id === '' ? null : ['id' => $id, 'name' => null];
                if ($incharge === null) {
                    $add(self::WARNING, 'incharge', "{$j->location} has no in-charge on {$rowDate}: choose one before importing.");
                }
            }
        }

        // What already exists, and what this file already did to it.
        $existing = $number === '' ? null : DailyWork::withTrashed()->where('number', $number)->first(['id', 'status', 'inspection_result', 'resubmission_count', 'date', 'deleted_at']);
        $existingInfo = null;
        if ($existing !== null) {
            $existingInfo = ['id' => $existing->id, 'status' => $existing->status, 'inspection_result' => $existing->inspection_result, 'resubmissions' => (int) $existing->resubmission_count, 'date' => $existing->date?->toDateString(), 'deleted' => $existing->trashed()];
        } elseif (isset($seen[$number]) && $number !== '') {
            $existingInfo = ['id' => null, 'status' => 'new', 'inspection_result' => null, 'resubmissions' => $seen[$number]['resubmissions'], 'date' => $seen[$number]['date'], 'deleted' => false, 'in_file' => true];
            if ($seen[$number]['sheet'] === $sheet) {
                $add(self::ERROR, 'rfi_number', "The RFI number appears more than once in sheet {$sheet} (rows {$seen[$number]['row']} and {$index}).");
            }
        }

        $hasError = collect($issues)->contains(fn (array $i): bool => $i['level'] === self::ERROR);
        $action = 'create';
        if ($hasError) {
            $action = 'reject';
        } elseif ($existingInfo !== null) {
            if ($existingInfo['deleted']) {
                $add(self::ERROR, 'rfi_number', 'This RFI number belongs to a deleted work. Restore it first, or use another number.');
                $action = 'reject';
            } elseif ($existingInfo['date'] === $rowDate) {
                $add(self::WARNING, 'rfi_number', 'This RFI is already recorded for this date: nothing to import (re-importing is harmless).');
                $action = 'skip';
            } elseif (DailyWorkWorkflow::normalise($existingInfo['status']) === DailyWork::STATUS_COMPLETED && in_array($existingInfo['inspection_result'], ['pass', 'fail'], true)) {
                $add(self::WARNING, 'rfi_number', "Already completed ({$existingInfo['inspection_result']}): it is skipped, a completed RFI is not changed by an import.");
                $action = 'skip';
            } else {
                $n = $existingInfo['resubmissions'] + 1;
                $add(self::WARNING, 'rfi_number', "This RFI exists: it is recorded as resubmission {$n} of the same work.");
                $action = 'resubmit';
            }
        }
        if ($number !== '' && ! $hasError && $action !== 'skip') {
            $seen[$number] = ['date' => $rowDate, 'sheet' => $sheet, 'row' => $index, 'resubmissions' => ($existingInfo['resubmissions'] ?? -1) + 1];
        }

        $level = collect($issues)->contains(fn (array $i): bool => $i['level'] === self::ERROR) ? self::ERROR : (collect($issues)->contains(fn (array $i): bool => $i['level'] === self::WARNING) ? self::WARNING : self::VALID);

        return [
            'sheet' => $sheet, 'row' => $index, 'date' => $rowDate, 'rfi_number' => $number, 'type' => $type ?? $typeRaw, 'description' => $description, 'location' => $location,
            'side' => $side === '' ? null : $side, 'qty_layer' => $text($cells[6] ?? null) ?: null, 'planned_time' => $text($cells[7] ?? null) ?: null,
            'span' => $span === null ? null : ['from' => $span['from'], 'to' => $span['to']], 'jurisdiction' => $jurisdiction, 'incharge' => $incharge,
            'existing' => $existingInfo, 'action' => $action, 'level' => $level, 'issues' => $issues,
        ];
    }

    private function type(string $raw): ?string
    {
        foreach (DailyWork::$types as $t) {
            if (strcasecmp($t, $raw) === 0) {
                return $t;
            }
        }

        return null;
    }

    /** A date cell (text, Excel serial or date) as Y-m-d, or null. */
    public function date(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }
        if (is_numeric($value) && (float) $value > 20000 && (float) $value < 80000) {
            try {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->format('Y-m-d');
            } catch (\Throwable) {
                return null;
            }
        }
        if (is_string($value) && preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($value)) === 1) {
            return checkdate((int) substr(trim($value), 5, 2), (int) substr(trim($value), 8, 2), (int) substr(trim($value), 0, 4)) ? trim($value) : null;
        }
        try {
            return Carbon::parse((string) $value)->format('Y-m-d');
        } catch (\Throwable) {
            return null;
        }
    }
}
