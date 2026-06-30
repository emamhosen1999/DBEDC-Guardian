<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; }
        h2 { text-align: center; margin: 0; }
        .meta { text-align: center; color: #555; margin: 4px 0 12px; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #999; padding: 4px 6px; text-align: left; }
        th { background: #e3f2fd; }
    </style>
</head>
<body>
    <h2>Attendance Log</h2>
    <div class="meta">
        {{ $from->format('M d, Y') }} to {{ $to->format('M d, Y') }} &middot; Generated: {{ $generatedOn }}
    </div>
    <table>
        <thead>
            <tr>
                <th>Date</th><th>Employee</th><th>Employee ID</th><th>Department</th>
                <th>Designation</th><th>Clock In</th><th>Clock Out</th><th>Work Hours</th><th>Status</th>
            </tr>
        </thead>
        <tbody>
            @forelse ($rows as $row)
                <tr>
                    <td>{{ \Carbon\Carbon::parse($row['date'])->format('M d, Y') }}</td>
                    <td>{{ $row['employee_name'] }}</td>
                    <td>{{ $row['employee_id'] ?? '' }}</td>
                    <td>{{ $row['department'] ?? '' }}</td>
                    <td>{{ $row['designation'] ?? '' }}</td>
                    <td>{{ $row['clock_in'] ? \Carbon\Carbon::parse($row['clock_in'])->format('h:i A') : '—' }}</td>
                    <td>{{ $row['clock_out'] ? \Carbon\Carbon::parse($row['clock_out'])->format('h:i A') : '—' }}</td>
                    <td>{{ $row['work_hours'] }}</td>
                    <td>{{ $row['remarks'] }}</td>
                </tr>
            @empty
                <tr><td colspan="9" style="text-align:center;">No records for the selected range and filters.</td></tr>
            @endforelse
        </tbody>
    </table>
</body>
</html>
