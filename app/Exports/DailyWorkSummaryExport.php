<?php

namespace App\Exports;

use Carbon\Carbon;
use Maatwebsite\Excel\Concerns\Exportable;
use Maatwebsite\Excel\Concerns\FromArray;
use Maatwebsite\Excel\Concerns\ShouldAutoSize;
use Maatwebsite\Excel\Concerns\WithEvents;
use Maatwebsite\Excel\Concerns\WithMultipleSheets;
use Maatwebsite\Excel\Concerns\WithStyles;
use Maatwebsite\Excel\Concerns\WithTitle;
use Maatwebsite\Excel\Events\AfterSheet;
use PhpOffice\PhpSpreadsheet\Style\Border;
use PhpOffice\PhpSpreadsheet\Style\Fill;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Server-side Excel export for the Daily Work Summary page.
 * Produces a multi-sheet workbook: Summary, Daily Breakdown, Per-Incharge,
 * Type/Status Distribution.
 */
class DailyWorkSummaryExport implements WithMultipleSheets
{
    use Exportable;

    private array $summaries;

    private array $analytics;

    private array $filters;

    public function __construct(array $summaries, array $analytics, array $filters = [])
    {
        $this->summaries = $summaries;
        $this->analytics = $analytics;
        $this->filters = $filters;
    }

    public function sheets(): array
    {
        return [
            new DailyWorkSummaryOverviewSheet($this->analytics, $this->filters),
            new DailyWorkSummaryDailyBreakdownSheet($this->summaries),
            new DailyWorkSummaryInchargeSheet($this->analytics['inchargePerformance'] ?? []),
            new DailyWorkSummaryDistributionSheet($this->analytics),
        ];
    }
}

/* ----------------- Overview Sheet ----------------- */
class DailyWorkSummaryOverviewSheet implements FromArray, ShouldAutoSize, WithEvents, WithStyles, WithTitle
{
    private array $analytics;

    private array $filters;

    public function __construct(array $analytics, array $filters)
    {
        $this->analytics = $analytics;
        $this->filters = $filters;
    }

    public function title(): string
    {
        return 'Overview';
    }

    public function array(): array
    {
        $kpi = $this->analytics['kpi'] ?? [];
        $highlights = $this->analytics['highlights'] ?? [];

        $period = ($this->filters['startDate'] ?? null) && ($this->filters['endDate'] ?? null)
            ? Carbon::parse($this->filters['startDate'])->format('M d, Y').' - '.Carbon::parse($this->filters['endDate'])->format('M d, Y')
            : 'All Time';

        $rows = [
            ['Daily Work Summary Report'],
            ['Period', $period],
            ['Generated On', Carbon::now()->format('F d, Y h:i A')],
            [],
            ['Key Performance Indicators'],
            ['Metric', 'Value'],
            ['Total Works', $kpi['totalWorks'] ?? 0],
            ['Completed', $kpi['completed'] ?? 0],
            ['Pending', $kpi['pending'] ?? 0],
            ['RFI Submissions', $kpi['rfiSubmissions'] ?? 0],
            ['Completion Rate', ($kpi['completionRate'] ?? 0).'%'],
            ['RFI Submission Rate', ($kpi['rfiRate'] ?? 0).'%'],
            ['Total Resubmissions', $kpi['totalResubmissions'] ?? 0],
            ['Works with Resubmissions', $kpi['worksWithResubmissions'] ?? 0],
            ['Average Daily Works', $kpi['avgDailyWorks'] ?? 0],
            ['Trend Direction', ($kpi['trendDirection'] ?? 0).'% change in completion rate'],
            [],
            ['Highlights'],
            ['Metric', 'Date / Name', 'Value'],
            [
                'Best Day (Completion %)',
                $highlights['bestDay']['date'] ?? '—',
                ($highlights['bestDay']['completionRate'] ?? 0).'%',
            ],
            [
                'Worst Day (Completion %)',
                $highlights['worstDay']['date'] ?? '—',
                ($highlights['worstDay']['completionRate'] ?? 0).'%',
            ],
            [
                'Busiest Day (Total Works)',
                $highlights['busiestDay']['date'] ?? '—',
                $highlights['busiestDay']['total'] ?? 0,
            ],
            [
                'Top Incharge (by Completion %)',
                $highlights['topIncharge']['incharge'] ?? '—',
                ($highlights['topIncharge']['completionRate'] ?? 0).'%',
            ],
            [
                'Most Common Work Type',
                $highlights['mostCommonType']['name'] ?? '—',
                $highlights['mostCommonType']['value'] ?? 0,
            ],
        ];

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 16]],
            5 => ['font' => ['bold' => true, 'size' => 12]],
            6 => ['font' => ['bold' => true]],
            18 => ['font' => ['bold' => true, 'size' => 12]],
            19 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->mergeCells('A1:C1');
                // Header bands
                foreach (['A5:C5', 'A18:C18'] as $range) {
                    $sheet->getStyle($range)->getFill()
                        ->setFillType(Fill::FILL_SOLID)
                        ->getStartColor()->setRGB('1976D2');
                    $sheet->getStyle($range)->getFont()->getColor()->setRGB('FFFFFF');
                }
            },
        ];
    }
}

/* ----------------- Daily Breakdown Sheet ----------------- */
class DailyWorkSummaryDailyBreakdownSheet implements FromArray, ShouldAutoSize, WithEvents, WithStyles, WithTitle
{
    private array $summaries;

    public function __construct(array $summaries)
    {
        $this->summaries = $summaries;
    }

    public function title(): string
    {
        return 'Daily Breakdown';
    }

    public function array(): array
    {
        $rows = [[
            'Date',
            'Total Works',
            'Resubmissions',
            'Embankment',
            'Structure',
            'Pavement',
            'Completed',
            'Pending',
            'Completion %',
            'RFI Submissions',
            'RFI %',
        ]];

        foreach ($this->summaries as $s) {
            $rows[] = [
                $s['date'] ?? '',
                $s['totalDailyWorks'] ?? 0,
                $s['resubmissions'] ?? 0,
                $s['embankment'] ?? 0,
                $s['structure'] ?? 0,
                $s['pavement'] ?? 0,
                $s['completed'] ?? 0,
                $s['pending'] ?? 0,
                ($s['completionPercentage'] ?? 0).'%',
                $s['rfiSubmissions'] ?? 0,
                ($s['rfiSubmissionPercentage'] ?? 0).'%',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highest = $sheet->getHighestColumn();
                $sheet->getStyle('A1:'.$highest.'1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('1976D2');
                $sheet->getStyle('A1:'.$highest.$sheet->getHighestRow())
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}

/* ----------------- Per-Incharge Sheet ----------------- */
class DailyWorkSummaryInchargeSheet implements FromArray, ShouldAutoSize, WithEvents, WithStyles, WithTitle
{
    private array $inchargePerformance;

    public function __construct(array $inchargePerformance)
    {
        $this->inchargePerformance = $inchargePerformance;
    }

    public function title(): string
    {
        return 'Per-Incharge';
    }

    public function array(): array
    {
        $rows = [[
            'Incharge',
            'Total Works',
            'Completed',
            'Pending',
            'RFI Submissions',
            'Completion %',
        ]];

        foreach ($this->inchargePerformance as $row) {
            $rows[] = [
                $row['incharge'] ?? '—',
                $row['total'] ?? 0,
                $row['completed'] ?? 0,
                $row['pending'] ?? 0,
                $row['rfiSubmissions'] ?? 0,
                ($row['completionRate'] ?? 0).'%',
            ];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $highest = $sheet->getHighestColumn();
                $sheet->getStyle('A1:'.$highest.'1')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('1976D2');
                $sheet->getStyle('A1:'.$highest.$sheet->getHighestRow())
                    ->getBorders()->getAllBorders()->setBorderStyle(Border::BORDER_THIN);
            },
        ];
    }
}

/* ----------------- Distribution Sheet ----------------- */
class DailyWorkSummaryDistributionSheet implements FromArray, ShouldAutoSize, WithEvents, WithStyles, WithTitle
{
    private array $analytics;

    public function __construct(array $analytics)
    {
        $this->analytics = $analytics;
    }

    public function title(): string
    {
        return 'Distribution';
    }

    public function array(): array
    {
        $type = $this->analytics['typeBreakdown'] ?? [];
        $status = $this->analytics['statusBreakdown'] ?? [];

        $rows = [
            ['Work Type Distribution'],
            ['Type', 'Count'],
        ];
        foreach ($type as $t) {
            $rows[] = [$t['name'] ?? '—', $t['value'] ?? 0];
        }

        $rows[] = [];
        $rows[] = ['Status Distribution'];
        $rows[] = ['Status', 'Count'];
        foreach ($status as $s) {
            $rows[] = [$s['name'] ?? '—', $s['value'] ?? 0];
        }

        return $rows;
    }

    public function styles(Worksheet $sheet)
    {
        return [
            1 => ['font' => ['bold' => true, 'size' => 12]],
            2 => ['font' => ['bold' => true]],
        ];
    }

    public function registerEvents(): array
    {
        return [
            AfterSheet::class => function (AfterSheet $event) {
                $sheet = $event->sheet->getDelegate();
                $sheet->getStyle('A2:B2')->getFill()
                    ->setFillType(Fill::FILL_SOLID)
                    ->getStartColor()->setRGB('1976D2');
                $sheet->getStyle('A2:B2')->getFont()->getColor()->setRGB('FFFFFF');

                // Style status section header dynamically
                $highest = $sheet->getHighestRow();
                for ($r = 1; $r <= $highest; $r++) {
                    $cell = $sheet->getCell('A'.$r)->getValue();
                    if ($cell === 'Status' || $cell === 'Type') {
                        $sheet->getStyle('A'.$r.':B'.$r)->getFill()
                            ->setFillType(Fill::FILL_SOLID)
                            ->getStartColor()->setRGB('1976D2');
                        $sheet->getStyle('A'.$r.':B'.$r)->getFont()
                            ->setBold(true)
                            ->getColor()->setRGB('FFFFFF');
                    }
                }
            },
        ];
    }
}
