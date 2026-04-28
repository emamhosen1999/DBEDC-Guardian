<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithColumnWidths;
use Maatwebsite\Excel\Concerns\WithColumnFormatting;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use PhpOffice\PhpSpreadsheet\Style\Alignment;
use PhpOffice\PhpSpreadsheet\Style\NumberFormat;

class DailyWorksSummaryExport implements FromCollection, WithHeadings, WithMapping, WithStyles, WithColumnWidths, WithColumnFormatting
{
    protected $data;
    protected $columns;

    public function __construct(array $data, array $columns = [])
    {
        $this->data = $data;
        $this->columns = $columns;
    }

    public function collection()
    {
        return collect($this->data);
    }

    public function headings(): array
    {
        if (empty($this->columns)) {
            return [
                'Date',
                'Total Daily Works',
                'Completed',
                'Pending',
                'Completion %',
                'Embankment',
                'Structure',
                'Pavement',
                'RFI Submissions',
                'RFI Submission %',
                'Resubmissions',
            ];
        }

        // Map column names to readable headers
        $columnHeaders = [
            'date' => 'Date',
            'totalDailyWorks' => 'Total Daily Works',
            'completed' => 'Completed',
            'embankment' => 'Embankment',
            'structure' => 'Structure',
            'pavement' => 'Pavement',
            'resubmissions' => 'Resubmissions',
            'rfiSubmissions' => 'RFI Submissions',
        ];

        return array_map(function($col) use ($columnHeaders) {
            return $columnHeaders[$col] ?? ucfirst(str_replace('_', ' ', $col));
        }, $this->columns);
    }

    public function map($item): array
    {
        $totalDailyWorks = $item['totalDailyWorks'] ?? 0;
        $completed = $item['completed'] ?? 0;
        $completionPercentage = $totalDailyWorks > 0 ? round(($completed / $totalDailyWorks) * 100, 1) : 0;
        $rfiSubmissions = $item['rfiSubmissions'] ?? 0;
        $rfiSubmissionPercentage = $completed > 0 ? round(($rfiSubmissions / $completed) * 100, 1) : 0;
        $pending = $totalDailyWorks - $completed;

        if (empty($this->columns)) {
            return [
                $item['date'] ?? '',
                $totalDailyWorks,
                $completed,
                $pending,
                $completionPercentage / 100, // Excel stores percentages as decimals
                $item['embankment'] ?? 0,
                $item['structure'] ?? 0,
                $item['pavement'] ?? 0,
                $rfiSubmissions,
                $rfiSubmissionPercentage / 100, // Excel stores percentages as decimals
                $item['resubmissions'] ?? 0,
            ];
        }

        $result = [];
        foreach ($this->columns as $column) {
            if ($column === 'date') {
                $result[] = $item['date'] ?? '';
            } elseif ($column === 'totalDailyWorks') {
                $result[] = $totalDailyWorks;
            } elseif ($column === 'completed') {
                $result[] = $completed;
            } elseif ($column === 'embankment') {
                $result[] = $item['embankment'] ?? 0;
            } elseif ($column === 'structure') {
                $result[] = $item['structure'] ?? 0;
            } elseif ($column === 'pavement') {
                $result[] = $item['pavement'] ?? 0;
            } elseif ($column === 'resubmissions') {
                $result[] = $item['resubmissions'] ?? 0;
            } elseif ($column === 'rfiSubmissions') {
                $result[] = $rfiSubmissions;
            } else {
                $result[] = $item[$column] ?? '';
            }
        }

        return $result;
    }

    public function columnWidths(): array
    {
        if (empty($this->columns)) {
            return [
                'A' => 15, // Date
                'B' => 18, // Total Daily Works
                'C' => 12, // Completed
                'D' => 12, // Pending
                'E' => 14, // Completion %
                'F' => 14, // Embankment
                'G' => 12, // Structure
                'H' => 12, // Pavement
                'I' => 16, // RFI Submissions
                'J' => 16, // RFI Submission %
                'K' => 14, // Resubmissions
            ];
        }

        // Dynamic column widths based on selected columns
        $widths = [];
        $columnWidths = [
            'date' => 15,
            'totalDailyWorks' => 18,
            'completed' => 12,
            'embankment' => 14,
            'structure' => 12,
            'pavement' => 12,
            'resubmissions' => 14,
            'rfiSubmissions' => 16,
        ];

        $colLetter = 'A';
        foreach ($this->columns as $column) {
            $widths[$colLetter] = $columnWidths[$column] ?? 12;
            $colLetter++;
        }

        return $widths;
    }

    public function columnFormats(): array
    {
        if (empty($this->columns)) {
            return [
                'B' => NumberFormat::FORMAT_NUMBER,
                'C' => NumberFormat::FORMAT_NUMBER,
                'D' => NumberFormat::FORMAT_NUMBER,
                'E' => NumberFormat::FORMAT_PERCENTAGE_00,
                'F' => NumberFormat::FORMAT_NUMBER,
                'G' => NumberFormat::FORMAT_NUMBER,
                'H' => NumberFormat::FORMAT_NUMBER,
                'I' => NumberFormat::FORMAT_NUMBER,
                'J' => NumberFormat::FORMAT_PERCENTAGE_00,
                'K' => NumberFormat::FORMAT_NUMBER,
            ];
        }

        // Dynamic column formats based on selected columns
        $formats = [];
        $columnFormats = [
            'totalDailyWorks' => NumberFormat::FORMAT_NUMBER,
            'completed' => NumberFormat::FORMAT_NUMBER,
            'embankment' => NumberFormat::FORMAT_NUMBER,
            'structure' => NumberFormat::FORMAT_NUMBER,
            'pavement' => NumberFormat::FORMAT_NUMBER,
            'resubmissions' => NumberFormat::FORMAT_NUMBER,
            'rfiSubmissions' => NumberFormat::FORMAT_NUMBER,
        ];

        $colLetter = 'A';
        foreach ($this->columns as $column) {
            if (isset($columnFormats[$column])) {
                $formats[$colLetter] = $columnFormats[$column];
            }
            $colLetter++;
        }

        return $formats;
    }

    public function styles(Worksheet $sheet)
    {
        $sheet->getStyle('1')->getFont()->setBold(true);
        $sheet->getStyle('1')->getFont()->setSize(12);
        $sheet->getStyle('1')->getFill()->setFillType(\PhpOffice\PhpSpreadsheet\Style\Fill::FILL_SOLID);
        $sheet->getStyle('1')->getFill()->getStartColor()->setARGB('3B82F6');
        $sheet->getStyle('1')->getFont()->getColor()->setARGB('FFFFFF');
        $sheet->getStyle('1')->getAlignment()->setHorizontal(Alignment::HORIZONTAL_CENTER);
        $sheet->getStyle('1')->getAlignment()->setVertical(Alignment::VERTICAL_CENTER);

        // Center the header row
        $sheet->getStyle('1')->getAlignment()->setWrapText(true);

        // Auto-height for rows
        $sheet->getDefaultRowDimension()->setRowHeight(-1);

        // Apply borders to all cells
        $sheet->getStyle('A1:K' . ($sheet->getHighestRow()))->getBorders()->getAllBorders()->setBorderStyle(\PhpOffice\PhpSpreadsheet\Style\Border::BORDER_THIN);

        // Align numeric columns to the right
        if (empty($this->columns)) {
            $numericColumns = ['B', 'C', 'D', 'E', 'F', 'G', 'H', 'I', 'J', 'K'];
            foreach ($numericColumns as $col) {
                $sheet->getStyle($col . '2:' . $col . $sheet->getHighestRow())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
            }
        } else {
            $colLetter = 'A';
            foreach ($this->columns as $column) {
                if (in_array($column, ['totalDailyWorks', 'completed', 'embankment', 'structure', 'pavement', 'resubmissions', 'rfiSubmissions'])) {
                    $sheet->getStyle($colLetter . '2:' . $colLetter . $sheet->getHighestRow())->getAlignment()->setHorizontal(Alignment::HORIZONTAL_RIGHT);
                }
                $colLetter++;
            }
        }

        return [];
    }
}
