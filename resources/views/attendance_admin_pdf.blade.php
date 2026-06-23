<!DOCTYPE html>
<html>

<head>
    <title>Attendance PDF</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
        }

        th {
            background-color: #eee;
        }
    </style>
</head>

<body>
    <h3 style="text-align:center">DBEDC Site Office Attendance - {{ $monthName }}</h3>
    <table>
        <thead>
            <tr>
                <th>SL</th>
                <th>Name</th>
                @for($d = $from->day; $d <= $to->day; $d++)
                    <th>{{ $d }}</th>
                    @endfor
                    @foreach($leaveTypes as $type)
                    <th>{{ $type->type }}</th>
                    @endforeach
                    <th>Remarks</th>
            </tr>
        </thead>
        <tbody>
            @foreach($users as $index => $user)
            <tr>
                <td>{{ $index + 1 }}</td>
                <td>{{ $user->name }}</td>
                @for($d = $from->day; $d <= $to->day; $d++)
                    @php
                    $date = \Carbon\Carbon::create($from->year, $from->month, $d)->toDateString();
                    $status = $attendanceData[$index][$date]['status'] ?? '#';
                    @endphp
                    <td>{{ $status }}</td>
                    @endfor
                    @foreach($leaveTypes as $type)
                    @php
                    $count = collect($attendanceData[$index])
                    ->where('remarks', 'On Leave')
                    ->where('status', $type->symbol)
                    ->count();
                    @endphp
                    <td>{{ $count > 0 ? $count : '-' }}</td>
                    @endforeach
                    <td></td>
            </tr>
            @endforeach
        </tbody>
    </table>

    @isset($summary)
    <div style="page-break-before: always;"></div>

    <h3 style="text-align:center">Per-Employee Summary &mdash; {{ $monthName }}</h3>
    <p style="font-style:italic; text-align:center; font-size:9px;">Whole-day leave/present model &mdash; half-days are not yet split (a half-day may count as a full day).</p>

    @php
        $rows = $summary['rows'] ?? [];
        $totals = [
            'present'          => 0,
            'absent'           => 0,
            'leave'            => 0,
            'ot_hours'         => 0,
            'late'             => 0,
            'holidays_worked'  => 0,
            'weekly_off_worked'=> 0,
            'working_days'     => 0,
        ];
        foreach ($rows as $row) {
            foreach ($totals as $key => $_) {
                $totals[$key] += ($row[$key] ?? 0);
            }
        }
    @endphp

    <table>
        <thead>
            <tr>
                <th>Employee</th>
                <th>Emp ID</th>
                <th>Department</th>
                <th>Present</th>
                <th>Absent</th>
                <th>Leave</th>
                <th>OT Hours</th>
                <th>Late</th>
                <th>Holidays Worked</th>
                <th>Weekly-off Worked</th>
                <th>Working Days</th>
                <th>Attendance %</th>
            </tr>
        </thead>
        <tbody>
            @forelse($rows as $row)
            <tr>
                <td>{{ $row['employee_name'] ?? '' }}</td>
                <td>{{ $row['employee_id'] ?? '' }}</td>
                <td>{{ $row['department'] ?? '' }}</td>
                <td>{{ $row['present'] ?? 0 }}</td>
                <td>{{ $row['absent'] ?? 0 }}</td>
                <td>{{ $row['leave'] ?? 0 }}</td>
                <td>{{ $row['ot_hours'] ?? 0 }}</td>
                <td>{{ $row['late'] ?? 0 }}</td>
                <td>{{ $row['holidays_worked'] ?? 0 }}</td>
                <td>{{ $row['weekly_off_worked'] ?? 0 }}</td>
                <td>{{ $row['working_days'] ?? 0 }}</td>
                <td>{{ $row['attendance_percentage'] ?? '' }}</td>
            </tr>
            @empty
            @endforelse
            <tr>
                <td colspan="3" style="font-weight:bold; text-align:right;">TOTAL</td>
                <td style="font-weight:bold;">{{ $totals['present'] }}</td>
                <td style="font-weight:bold;">{{ $totals['absent'] }}</td>
                <td style="font-weight:bold;">{{ $totals['leave'] }}</td>
                <td style="font-weight:bold;">{{ $totals['ot_hours'] }}</td>
                <td style="font-weight:bold;">{{ $totals['late'] }}</td>
                <td style="font-weight:bold;">{{ $totals['holidays_worked'] }}</td>
                <td style="font-weight:bold;">{{ $totals['weekly_off_worked'] }}</td>
                <td style="font-weight:bold;">{{ $totals['working_days'] }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
    @endisset
</body>

</html>