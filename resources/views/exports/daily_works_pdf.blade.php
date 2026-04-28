<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daily Works Export</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            font-size: 12px;
            margin: 20px;
        }
        h1 {
            color: #333;
            border-bottom: 2px solid #333;
            padding-bottom: 10px;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
        }
        th {
            background-color: #f5f5f5;
            border: 1px solid #ddd;
            padding: 8px;
            text-align: left;
            font-weight: bold;
        }
        td {
            border: 1px solid #ddd;
            padding: 8px;
        }
        tr:nth-child(even) {
            background-color: #f9f9f9;
        }
        .footer {
            margin-top: 30px;
            color: #666;
            font-size: 10px;
        }
    </style>
</head>
<body>
    <h1>Daily Works Export</h1>
    <p>Generated on: {{ date('Y-m-d H:i:s') }}</p>
    
    @if(!empty($data) && $data->count() > 0)
        <table>
            <thead>
                <tr>
                    @if(!empty($columns))
                        @foreach($columns as $column)
                            <th>{{ ucfirst(str_replace('_', ' ', str_replace('.', ' ', $column))) }}</th>
                        @endforeach
                    @else
                        <th>ID</th>
                        <th>Work ID</th>
                        <th>Date</th>
                        <th>Number</th>
                        <th>Status</th>
                        <th>Type</th>
                        <th>Location</th>
                        <th>Description</th>
                        <th>Incharge</th>
                        <th>Assigned</th>
                        <th>Completion Time</th>
                        <th>RFI Submission Date</th>
                        <th>RFI Response Status</th>
                        <th>RFI Response Date</th>
                        <th>Resubmission Count</th>
                        <th>Resubmission Date</th>
                        <th>Inspection Result</th>
                        <th>Created At</th>
                        <th>Updated At</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @foreach($data as $item)
                    <tr>
                        @if(!empty($columns))
                            @foreach($columns as $column)
                                <td>
                                    @if(str_contains($column, '.'))
                                        {{ data_get($item, $column, '') }}
                                    @else
                                        {{ $item[$column] ?? '' }}
                                    @endif
                                </td>
                            @endforeach
                        @else
                            <td>{{ $item['id'] ?? '' }}</td>
                            <td>{{ $item['work_id'] ?? '' }}</td>
                            <td>{{ $item['date'] ?? '' }}</td>
                            <td>{{ $item['number'] ?? '' }}</td>
                            <td>{{ $item['status'] ?? '' }}</td>
                            <td>{{ $item['type'] ?? '' }}</td>
                            <td>{{ $item['location'] ?? '' }}</td>
                            <td>{{ $item['description'] ?? '' }}</td>
                            <td>{{ $item['incharge'] ?? '' }}</td>
                            <td>{{ $item['assigned'] ?? '' }}</td>
                            <td>{{ $item['completion_time'] ?? '' }}</td>
                            <td>{{ $item['rfi_submission_date'] ?? '' }}</td>
                            <td>{{ $item['rfi_response_status'] ?? '' }}</td>
                            <td>{{ $item['rfi_response_date'] ?? '' }}</td>
                            <td>{{ $item['resubmission_count'] ?? 0 }}</td>
                            <td>{{ $item['resubmission_date'] ?? '' }}</td>
                            <td>{{ $item['inspection_result'] ?? '' }}</td>
                            <td>{{ $item['created_at'] ?? '' }}</td>
                            <td>{{ $item['updated_at'] ?? '' }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        <div class="footer">
            <p>Total Records: {{ $data->count() }}</p>
        </div>
    @else
        <p>No data available for export.</p>
    @endif
</body>
</html>
