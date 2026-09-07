<?php

namespace App\Console\Commands;

use App\Models\OmDefect;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Console\Command;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date;

class ImportOmExcelFormat extends Command
{
    protected $signature = 'om:import-maintenance-excel {file?}';
    protected $description = 'Ingest real DBEDC concession road maintenance defect register from Excel format';

    public function handle(): int
    {
        $filePath = $this->argument('file') ?: base_path('DBEDC_Maintenance_Format _ Updated(1).xlsx');

        if (! file_exists($filePath)) {
            $this->error("Excel file not found at: {$filePath}");
            return 1;
        }

        $this->info("Loading workbook from: {$filePath} ...");
        $spreadsheet = IOFactory::load($filePath);
        $sheet = $spreadsheet->getSheetByName('Defect_Register');

        if (! $sheet) {
            $this->error("Sheet 'Defect_Register' not found in workbook!");
            return 1;
        }

        // Find Habib user
        $habibUser = User::where('employee_id', 127)->first() ?: User::where('name', 'like', '%Habib%')->first();
        $habibId = $habibUser ? $habibUser->id : null;

        $highestRow = $sheet->getHighestRow();
        $this->info("Scanning rows up to row {$highestRow} ...");

        $imported = 0;
        $updated = 0;

        for ($row = 3; $row <= $highestRow; $row++) {
            $sl = trim((string) $sheet->getCell('A' . $row)->getValue());
            if ($sl === '' || ! is_numeric($sl)) {
                continue;
            }

            $dateRaw = $sheet->getCell('B' . $row)->getValue();
            $dateIdentified = null;
            if (is_numeric($dateRaw)) {
                $dateIdentified = Carbon::instance(Date::excelToDateTimeObject($dateRaw))->startOfDay();
            } elseif (! empty($dateRaw)) {
                try {
                    $dateIdentified = Carbon::parse($dateRaw)->startOfDay();
                } catch (\Throwable $e) {
                    $dateIdentified = now()->startOfDay();
                }
            }

            $chainage = trim((string) $sheet->getCell('C' . $row)->getValue()) ?: 'K4+000';
            $location = trim((string) $sheet->getCell('D' . $row)->getValue()) ?: 'Main Carriageway';
            $category = trim((string) $sheet->getCell('E' . $row)->getValue()) ?: 'Other';
            $severityRaw = trim((string) $sheet->getCell('F' . $row)->getValue()) ?: 'Moderate';
            $description = trim((string) $sheet->getCell('G' . $row)->getValue()) ?: "Defect at {$chainage}";
            $photoRef = trim((string) $sheet->getCell('H' . $row)->getValue());
            $identifiedBy = trim((string) $sheet->getCell('I' . $row)->getValue());
            $responsibleParty = trim((string) $sheet->getCell('J' . $row)->getValue()) ?: 'O&M Contractor';
            $recommendedAction = trim((string) $sheet->getCell('K' . $row)->getValue());

            $dateNotifiedRaw = $sheet->getCell('L' . $row)->getValue();
            $dateNotified = is_numeric($dateNotifiedRaw)
                ? Carbon::instance(Date::excelToDateTimeObject($dateNotifiedRaw))->toDateString()
                : null;

            $targetDateRaw = $sheet->getCell('M' . $row)->getValue();
            $targetRepairDate = is_numeric($targetDateRaw)
                ? Carbon::instance(Date::excelToDateTimeObject($targetDateRaw))->toDateString()
                : null;

            $statusRaw = trim((string) $sheet->getCell('N' . $row)->getValue()) ?: 'Open';

            // Normalize severity
            $severity = 'medium';
            $slaHours = 48;
            $sevLower = strtolower($severityRaw);
            if (str_contains($sevLower, 'critical')) {
                $severity = 'critical';
                $slaHours = 4;
            } elseif (str_contains($sevLower, 'major')) {
                $severity = 'high';
                $slaHours = 24;
            } elseif (str_contains($sevLower, 'minor')) {
                $severity = 'low';
                $slaHours = 72;
            }

            // Normalize status
            $status = 'reported';
            if (str_contains($statusRaw, 'In Progress')) {
                $status = 'in_repair';
            } elseif (str_contains($statusRaw, 'Repaired')) {
                $status = 'rectified';
            } elseif (str_contains($statusRaw, 'Closed') || str_contains($statusRaw, 'Verified')) {
                $status = 'verified_closed';
            }

            // Standardize defect number
            $defectNumber = sprintf('DEF-2026-%04d', (int) $sl);

            // Calculate direction from location/chainage
            $direction = 'both';
            if (str_contains(strtolower($location), 'left') || str_contains($location, '- L')) {
                $direction = 'northbound';
            } elseif (str_contains(strtolower($location), 'right') || str_contains($location, '- R')) {
                $direction = 'southbound';
            } elseif (str_contains(strtolower($location), 'median')) {
                $direction = 'median';
            }

            $defect = OmDefect::updateOrCreate(
                ['defect_number' => $defectNumber],
                [
                    'excel_sl' => (string) $sl,
                    'title' => substr("{$category} at {$chainage} ({$location})", 0, 190),
                    'distress_type' => $category,
                    'chainage' => $chainage,
                    'direction' => $direction,
                    'location_carriageway' => $location,
                    'severity' => $severity,
                    'sla_hours' => $slaHours,
                    'sla_due_at' => $targetRepairDate ?: ($dateIdentified ? $dateIdentified->copy()->addHours($slaHours) : now()->addHours($slaHours)),
                    'status' => $status,
                    'reported_by' => $habibId,
                    'description' => $description . ($identifiedBy ? " [Identified by: {$identifiedBy}]" : ''),
                    'responsible_party' => $responsibleParty,
                    'recommended_action' => $recommendedAction,
                    'date_notified' => $dateNotified,
                    'target_repair_date' => $targetRepairDate,
                    'photo_reference' => $photoRef,
                    'created_at' => $dateIdentified ?: now(),
                ]
            );

            if ($defect->wasRecentlyCreated) {
                $imported++;
            } else {
                $updated++;
            }
        }

        $this->info("Import complete! Newly imported: {$imported}, Updated: {$updated}.");
        return 0;
    }
}
