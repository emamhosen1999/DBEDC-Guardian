<?php

namespace App\Exports;

use Maatwebsite\Excel\Concerns\FromCollection;
use Maatwebsite\Excel\Concerns\WithHeadings;
use Maatwebsite\Excel\Concerns\WithMapping;
use Maatwebsite\Excel\Concerns\WithStyles;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;

class DailyWorksExport implements FromCollection, WithHeadings, WithMapping, WithStyles, ShouldAutoSize
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
                'ID',
                'Work ID',
                'Date',
                'Number',
                'Status',
                'Type',
                'Location',
                'Description',
                'Incharge',
                'Assigned',
                'Completion Time',
                'RFI Submission Date',
                'RFI Response Status',
                'RFI Response Date',
                'Resubmission Count',
                'Resubmission Date',
                'Inspection Result',
                'Created At',
                'Updated At',
            ];
        }

        // Map column names to readable headers
        $columnHeaders = [
            'id' => 'ID',
            'work_id' => 'Work ID',
            'date' => 'Date',
            'number' => 'Number',
            'status' => 'Status',
            'type' => 'Type',
            'location' => 'Location',
            'description' => 'Description',
            'incharge' => 'Incharge',
            'assigned' => 'Assigned',
            'completion_time' => 'Completion Time',
            'rfi_submission_date' => 'RFI Submission Date',
            'rfi_response_status' => 'RFI Response Status',
            'rfi_response_date' => 'RFI Response Date',
            'resubmission_count' => 'Resubmission Count',
            'resubmission_date' => 'Resubmission Date',
            'inspection_result' => 'Inspection Result',
            'created_at' => 'Created At',
            'updated_at' => 'Updated At',
            'incharge_user.name' => 'Incharge Name',
            'assigned_user.name' => 'Assigned Name',
        ];

        return array_map(function($col) use ($columnHeaders) {
            return $columnHeaders[$col] ?? ucfirst(str_replace('_', ' ', $col));
        }, $this->columns);
    }

    public function map($item): array
    {
        if (empty($this->columns)) {
            return [
                $item['id'] ?? '',
                $item['work_id'] ?? '',
                $item['date'] ?? '',
                $item['number'] ?? '',
                $item['status'] ?? '',
                $item['type'] ?? '',
                $item['location'] ?? '',
                $item['description'] ?? '',
                $item['incharge'] ?? '',
                $item['assigned'] ?? '',
                $item['completion_time'] ?? '',
                $item['rfi_submission_date'] ?? '',
                $item['rfi_response_status'] ?? '',
                $item['rfi_response_date'] ?? '',
                $item['resubmission_count'] ?? 0,
                $item['resubmission_date'] ?? '',
                $item['inspection_result'] ?? '',
                $item['created_at'] ?? '',
                $item['updated_at'] ?? '',
            ];
        }

        $result = [];
        foreach ($this->columns as $column) {
            // Handle nested keys like incharge_user.name
            if (str_contains($column, '.')) {
                $keys = explode('.', $column);
                $value = $item;
                foreach ($keys as $key) {
                    $value = $value[$key] ?? '';
                }
                $result[] = $value;
            } else {
                $result[] = $item[$column] ?? '';
            }
        }

        return $result;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            // Style the first row as bold text
            1 => ['font' => ['bold' => true]],
        ];
    }
}
