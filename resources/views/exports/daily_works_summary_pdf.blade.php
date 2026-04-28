<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Daily Works Summary Export</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            font-size: 11px;
            margin: 0;
            padding: 0;
            color: #333;
        }
        .header {
            text-align: center;
            margin-bottom: 20px;
            padding: 20px;
            border-bottom: 3px solid #2563eb;
            background-color: #ffffff;
        }
        .header h1 {
            color: #1e40af;
            margin: 0 0 10px 0;
            font-size: 28px;
            font-weight: bold;
            page-break-after: avoid;
        }
        .header p {
            color: #6b7280;
            margin: 5px 0;
            font-size: 13px;
            page-break-after: avoid;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 20px;
            background-color: white;
            box-shadow: 0 1px 3px rgba(0,0,0,0.1);
        }
        thead {
            background: linear-gradient(135deg, #3b82f6 0%, #1d4ed8 100%);
            color: white;
        }
        th {
            padding: 12px 10px;
            text-align: left;
            font-weight: 600;
            font-size: 11px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            border: 1px solid #1d4ed8;
        }
        td {
            padding: 10px;
            text-align: left;
            border: 1px solid #e5e7eb;
            color: #374151;
        }
        tr:nth-child(even) {
            background-color: #f9fafb;
        }
        tr:hover {
            background-color: #eff6ff;
        }
        .numeric {
            text-align: right !important;
            font-family: 'Courier New', monospace;
            font-weight: 600;
        }
        .footer {
            margin-top: 30px;
            padding-top: 15px;
            border-top: 1px solid #e5e7eb;
            color: #6b7280;
            font-size: 10px;
            text-align: center;
        }
        .footer p {
            margin: 3px 0;
        }
        .summary-stats {
            margin-top: 20px;
            padding: 15px;
            background-color: #f0f9ff;
            border: 1px solid #bfdbfe;
            border-radius: 8px;
        }
        .summary-stats h3 {
            margin: 0 0 10px 0;
            color: #1e40af;
            font-size: 14px;
        }
        .summary-stats table {
            margin-top: 0;
            box-shadow: none;
        }
    </style>
</head>
<body>
    <div class="header">
        <h1>Daily Works Summary Report</h1>
        <p>Generated on: {{ date('F j, Y, g:i a') }}</p>
    </div>
    
    @if(!empty($data) && count($data) > 0)
        <table>
            <thead>
                <tr>
                    @if(!empty($columns))
                        @foreach($columns as $column)
                            <th>{{ ucfirst(str_replace('_', ' ', $column)) }}</th>
                        @endforeach
                    @else
                        <th>Date</th>
                        <th class="numeric">Total Works</th>
                        <th class="numeric">Completed</th>
                        <th class="numeric">Pending</th>
                        <th class="numeric">Completion %</th>
                        <th class="numeric">Embankment</th>
                        <th class="numeric">Structure</th>
                        <th class="numeric">Pavement</th>
                        <th class="numeric">RFI Submissions</th>
                        <th class="numeric">RFI %</th>
                        <th class="numeric">Resubmissions</th>
                    @endif
                </tr>
            </thead>
            <tbody>
                @php
                    $totalWorks = 0;
                    $totalCompleted = 0;
                    $totalEmbankment = 0;
                    $totalStructure = 0;
                    $totalPavement = 0;
                    $totalRfiSubmissions = 0;
                    $totalResubmissions = 0;
                @endphp
                @foreach($data as $item)
                    <?php
                        $totalDailyWorks = $item['totalDailyWorks'] ?? 0;
                        $completed = $item['completed'] ?? 0;
                        $completionPercentage = $totalDailyWorks > 0 ? round(($completed / $totalDailyWorks) * 100, 1) : 0;
                        $rfiSubmissions = $item['rfiSubmissions'] ?? 0;
                        $rfiSubmissionPercentage = $completed > 0 ? round(($rfiSubmissions / $completed) * 100, 1) : 0;
                        $pending = $totalDailyWorks - $completed;
                        
                        $totalWorks += $totalDailyWorks;
                        $totalCompleted += $completed;
                        $totalEmbankment += ($item['embankment'] ?? 0);
                        $totalStructure += ($item['structure'] ?? 0);
                        $totalPavement += ($item['pavement'] ?? 0);
                        $totalRfiSubmissions += $rfiSubmissions;
                        $totalResubmissions += ($item['resubmissions'] ?? 0);
                    ?>
                    <tr>
                        @if(!empty($columns))
                            @foreach($columns as $column)
                                <td @if(in_array($column, ['totalDailyWorks', 'completed', 'embankment', 'structure', 'pavement', 'resubmissions', 'rfiSubmissions'])) class="numeric" @endif>
                                    @if($column === 'date')
                                        {{ \Carbon\Carbon::parse($item['date'])->format('M j, Y') }}
                                    @elseif($column === 'totalDailyWorks')
                                        {{ number_format($totalDailyWorks) }}
                                    @elseif($column === 'completed')
                                        {{ number_format($completed) }}
                                    @elseif($column === 'embankment')
                                        {{ number_format($item['embankment'] ?? 0) }}
                                    @elseif($column === 'structure')
                                        {{ number_format($item['structure'] ?? 0) }}
                                    @elseif($column === 'pavement')
                                        {{ number_format($item['pavement'] ?? 0) }}
                                    @elseif($column === 'resubmissions')
                                        {{ number_format($item['resubmissions'] ?? 0) }}
                                    @elseif($column === 'rfiSubmissions')
                                        {{ number_format($rfiSubmissions) }}
                                    @else
                                        {{ $item[$column] ?? '' }}
                                    @endif
                                </td>
                            @endforeach
                        @else
                            <td>{{ \Carbon\Carbon::parse($item['date'])->format('M j, Y') }}</td>
                            <td class="numeric">{{ number_format($totalDailyWorks) }}</td>
                            <td class="numeric">{{ number_format($completed) }}</td>
                            <td class="numeric">{{ number_format($pending) }}</td>
                            <td class="numeric">{{ $completionPercentage }}%</td>
                            <td class="numeric">{{ number_format($item['embankment'] ?? 0) }}</td>
                            <td class="numeric">{{ number_format($item['structure'] ?? 0) }}</td>
                            <td class="numeric">{{ number_format($item['pavement'] ?? 0) }}</td>
                            <td class="numeric">{{ number_format($rfiSubmissions) }}</td>
                            <td class="numeric">{{ $rfiSubmissionPercentage }}%</td>
                            <td class="numeric">{{ number_format($item['resubmissions'] ?? 0) }}</td>
                        @endif
                    </tr>
                @endforeach
            </tbody>
        </table>
        
        <div class="summary-stats">
            <h3>Overall Summary</h3>
            <table>
                <tr>
                    <td><strong>Total Records:</strong></td>
                    <td class="numeric">{{ count($data) }} days</td>
                </tr>
                <tr>
                    <td><strong>Total Works:</strong></td>
                    <td class="numeric">{{ number_format($totalWorks) }}</td>
                </tr>
                <tr>
                    <td><strong>Total Completed:</strong></td>
                    <td class="numeric">{{ number_format($totalCompleted) }}</td>
                </tr>
                <tr>
                    <td><strong>Overall Completion Rate:</strong></td>
                    <td class="numeric">{{ $totalWorks > 0 ? round(($totalCompleted / $totalWorks) * 100, 1) : 0 }}%</td>
                </tr>
            </table>
        </div>
        
        <div class="footer">
            <p>Daily Works Analytics Report</p>
            <p>Generated by Aero Enterprise Suite</p>
            <p>Page 1 of 1</p>
        </div>
    @else
        <p style="text-align: center; color: #6b7280; padding: 40px;">No data available for export.</p>
    @endif
</body>
</html>
