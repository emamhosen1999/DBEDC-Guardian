<!DOCTYPE html>
<html>

<head>
    <title>Attendance Summary PDF</title>
    <style>
        body {
            font-family: DejaVu Sans, sans-serif;
            font-size: 10px;
        }

        h2, h3, p {
            text-align: center;
            margin: 2px 0;
        }

        h2 {
            font-size: 13px;
            margin-bottom: 2px;
        }

        h3 {
            font-size: 11px;
            font-weight: bold;
        }

        .caveat {
            font-style: italic;
            font-size: 9px;
            margin-bottom: 8px;
        }

        table {
            border-collapse: collapse;
            width: 100%;
            margin-top: 6px;
        }

        th,
        td {
            border: 1px solid #000;
            padding: 3px 4px;
            text-align: center;
        }

        th {
            background-color: #e0e0e0;
            font-weight: bold;
        }

        .total-row {
            font-weight: bold;
        }

        .text-left {
            text-align: left;
        }
    </style>
</head>

<body>
    <h2>Dhaka Bypass Expressway Development Company Ltd. — Monthly Attendance Summary</h2>
    <h3>
        {{ $summary['meta']['month'] }}{{ $summary['meta']['departmentName'] ? ' — ' . $summary['meta']['departmentName'] : '' }}
    </h3>
    <p class="caveat">Whole-day leave/present model — half-days are not yet split (a half-day may count as a full day).</p>

    @php
        $totals = [
            'present'          => 0,
            'absent'           => 0,
            'leave'            => 0,
            'ot_hours'         => 0.0,
            'late'             => 0,
            'holidays_worked'  => 0,
            'weekly_off_worked'=> 0,
            'working_days'     => 0,
        ];
        foreach ($summary['rows'] as $r) {
            $totals['present']           += $r['present'];
            $totals['absent']            += $r['absent'];
            $totals['leave']             += $r['leave'];
            $totals['ot_hours']          += $r['ot_hours'];
            $totals['late']              += $r['late'];
            $totals['holidays_worked']   += $r['holidays_worked'];
            $totals['weekly_off_worked'] += $r['weekly_off_worked'];
            $totals['working_days']      += $r['working_days'];
        }
    @endphp

    <table>
        <thead>
            <tr>
                <th class="text-left">Employee</th>
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
            @foreach($summary['rows'] as $row)
            <tr>
                <td class="text-left">{{ $row['employee_name'] }}</td>
                <td>{{ $row['employee_id'] }}</td>
                <td>{{ $row['department'] }}</td>
                <td>{{ $row['present'] }}</td>
                <td>{{ $row['absent'] }}</td>
                <td>{{ $row['leave'] }}</td>
                <td>{{ $row['ot_hours'] }}</td>
                <td>{{ $row['late'] }}</td>
                <td>{{ $row['holidays_worked'] }}</td>
                <td>{{ $row['weekly_off_worked'] }}</td>
                <td>{{ $row['working_days'] }}</td>
                <td>{{ $row['attendance_percentage'] }}</td>
            </tr>
            @endforeach
            <tr class="total-row">
                <td class="text-left">TOTAL</td>
                <td></td>
                <td></td>
                <td>{{ $totals['present'] }}</td>
                <td>{{ $totals['absent'] }}</td>
                <td>{{ $totals['leave'] }}</td>
                <td>{{ round($totals['ot_hours'], 1) }}</td>
                <td>{{ $totals['late'] }}</td>
                <td>{{ $totals['holidays_worked'] }}</td>
                <td>{{ $totals['weekly_off_worked'] }}</td>
                <td>{{ $totals['working_days'] }}</td>
                <td></td>
            </tr>
        </tbody>
    </table>
</body>

</html>
