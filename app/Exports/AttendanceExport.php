<?php

namespace App\Exports;

use App\Exports\Register\ReportStyle;
use App\Models\HRM\LeaveSetting;
use App\Services\Attendance\AttendanceReportService;
use App\Support\Attendance\AttendanceSymbol;
use Carbon\Carbon;
use Illuminate\Support\Facades\Storage;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

/**
 * The Daily Timesheet (one day, one row per employee) in the house report format.
 *
 * Day status comes from the same engine as the register and the calendar, so a person is
 * Present / Absent / Weekly Off / on a named leave / on a Festival Holiday here exactly as there,
 * and the Day column carries the register symbol.
 */
class AttendanceExport
{
    public const HEADINGS = [
        'No.', 'Date', 'Employee Name', 'Employee ID', 'Designation', 'Department', 'Phone',
        'Day', 'Status', 'Clock In', 'Clock Out', 'Work Hours', 'Total Punches', 'Complete Punches', 'Remarks',
    ];

    public const WIDTHS = [4.5, 11, 24, 12, 18, 16, 12, 5, 17, 13, 13, 10, 8, 9, 24];

    /** 1-based columns whose text reads left aligned. */
    public const LEFT = [3, 5, 6, 15];

    protected string $date;

    protected ?int $departmentId;

    /** @var array<int, string>|null actor scope (DepartmentScope::visibleEmployeeIds); null = unrestricted */
    protected ?array $employeeIds;

    public function __construct(string $date, ?int $departmentId = null, ?array $employeeIds = null)
    {
        $this->date = $date;
        $this->departmentId = $departmentId;
        $this->employeeIds = $employeeIds;
    }

    public function day(): Carbon
    {
        return Carbon::parse($this->date)->startOfDay();
    }

    public function title(): string
    {
        return 'Daily Timesheet';
    }

    /** @return list<array<string, scalar|null>> keyed by the headings */
    public function collection(): array
    {
        $day = $this->day();
        $reports = app(AttendanceReportService::class);
        $leaveTypes = LeaveSetting::all();
        $users = $reports->getEmployeeUsersWithAttendanceAndLeaves($day->year, $day->month, $this->departmentId, null, null, null, $this->employeeIds);
        $holidays = $reports->getHolidaysForMonth($day->year, $day->month);
        $isToday = now()->toDateString() === $day->toDateString();

        $rows = [];
        foreach ($users as $user) {
            $data = $reports->getUserAttendanceData($user, $day->year, $day->month, $holidays, $leaveTypes)[$day->toDateString()] ?? null;
            $symbol = $data['symbol'] ?? AttendanceSymbol::NOT_YET;
            $attendances = $user->attendances
                ->filter(fn ($a) => Carbon::parse($a->date)->isSameDay($day))
                ->sortBy('punchin')
                ->values();

            $ins = [];
            $outs = [];
            $minutes = 0;
            $complete = 0;
            $open = false;
            foreach ($attendances as $a) {
                if (! $a->punchin) {
                    continue;
                }
                $ins[] = Carbon::parse($a->punchin)->format('h:i A');
                $outs[] = $a->punchout ? Carbon::parse($a->punchout)->format('h:i A') : 'No punch out';
                if ($a->punchout) {
                    $in = Carbon::parse($a->punchin);
                    $out = Carbon::parse($a->punchout);
                    if ($out->lt($in)) {
                        $out->addDay();
                    }
                    $minutes += (int) $in->diffInMinutes($out);
                    $complete++;
                } else {
                    $open = true;
                }
            }
            $numbered = fn (array $times) => implode("\n", array_map(fn ($t, $i) => ($i + 1).'. '.$t, $times, array_keys($times)));
            $remarks = $data['remarks'] ?? '';
            if ($ins !== []) {
                $remarks = $open ? ($isToday ? 'Currently Working' : 'Incomplete punches') : 'All punches complete';
            }
            $worked = $minutes > 0 ? intdiv($minutes, 60).'h '.($minutes % 60).'m' : '0h 0m';

            $rows[] = [
                'No.' => count($rows) + 1,
                'Date' => $day->format('M d, Y'),
                'Employee Name' => $user->name,
                'Employee ID' => $user->employee_id,
                'Designation' => $user->designation->title ?? 'N/A',
                'Department' => $user->department->name ?? '',
                'Phone' => $user->phone,
                'Day' => $symbol,
                'Status' => AttendanceSymbol::label($symbol),
                'Clock In' => $ins !== [] ? $numbered($ins) : '',
                'Clock Out' => $outs !== [] ? $numbered($outs) : '',
                'Work Hours' => $worked,
                'Total Punches' => $attendances->count(),
                'Complete Punches' => $complete,
                'Remarks' => $remarks,
            ];
        }

        return $rows;
    }

    /** "Total employees: 27 · Present 20 · On leave 2 · Absent 3 · Weekly off 1 · Festival 1 · Not yet recorded 0". */
    public function note(array $rows): string
    {
        $count = fn (array $symbols) => count(array_filter($rows, fn ($r) => in_array($r['Day'], $symbols, true)));
        $leave = [AttendanceSymbol::PERSONAL, AttendanceSymbol::SICK, AttendanceSymbol::MARITAL, AttendanceSymbol::FUNERAL, AttendanceSymbol::MATERNITY, AttendanceSymbol::ANNUAL, AttendanceSymbol::UNKNOWN_LEAVE];

        return 'Total employees: '.count($rows)
            .'  ·  Present: '.$count([AttendanceSymbol::PRESENT])
            .'  ·  Absent: '.$count([AttendanceSymbol::ABSENT])
            .'  ·  On leave: '.$count($leave)
            .'  ·  Weekly off: '.$count([AttendanceSymbol::DAY_OFF])
            .'  ·  Festival holiday: '.$count([AttendanceSymbol::FESTIVAL])
            .'  ·  Not yet recorded: '.$count([AttendanceSymbol::NOT_YET]);
    }

    public function build(): Spreadsheet
    {
        $rows = $this->collection();
        $spreadsheet = new Spreadsheet;
        $ws = $spreadsheet->getActiveSheet();
        $ws->setTitle($this->title());
        ReportStyle::tableReport($ws, $this->title(), $this->day(), $this->day(), self::HEADINGS, self::WIDTHS, array_map('array_values', $rows), [
            'note' => $this->note($rows), 'withKey' => true, 'leftAligned' => self::LEFT,
            'empty' => 'No employees in scope for this day.',
        ]);

        return $spreadsheet;
    }

    public function saveToDisk(string $filePath, string $disk = 'public'): void
    {
        $tmp = tempnam(sys_get_temp_dir(), 'daily');
        (new Xlsx($this->build()))->save($tmp);
        Storage::disk($disk)->makeDirectory('exports');
        Storage::disk($disk)->put($filePath, file_get_contents($tmp));
        @unlink($tmp);
    }

    public function pdf(): string
    {
        $rows = $this->collection();

        return ReportStyle::tablePdf($this->title(), $this->day(), $this->day(), self::HEADINGS, self::WIDTHS, array_map('array_values', $rows), [
            'note' => $this->note($rows), 'withKey' => true, 'leftAligned' => self::LEFT,
            'empty' => 'No employees in scope for this day.',
        ]);
    }
}
