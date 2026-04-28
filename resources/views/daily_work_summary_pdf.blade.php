<!DOCTYPE html>
<html>

<head>
    <meta charset="utf-8">
    <title>{{ $title }}</title>
    <style>
        @page {
            margin: 18mm 12mm 18mm 12mm;
        }

        body {
            font-family: Helvetica, Arial, sans-serif;
            font-size: 10px;
            color: #1f2937;
        }

        .header {
            border-bottom: 2px solid #1976D2;
            padding-bottom: 8px;
            margin-bottom: 12px;
        }

        .header h1 {
            font-size: 18px;
            color: #1976D2;
            margin: 0 0 4px 0;
        }

        .header .meta {
            font-size: 10px;
            color: #6b7280;
        }

        .section-title {
            font-size: 13px;
            font-weight: bold;
            color: #1976D2;
            margin-top: 14px;
            margin-bottom: 6px;
            border-bottom: 1px solid #d1d5db;
            padding-bottom: 3px;
        }

        .kpi-grid {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 8px;
        }

        .kpi-grid td {
            width: 25%;
            padding: 8px;
            border: 1px solid #e5e7eb;
            background: #f9fafb;
            vertical-align: top;
        }

        .kpi-label {
            font-size: 9px;
            color: #6b7280;
            text-transform: uppercase;
            letter-spacing: 0.05em;
        }

        .kpi-value {
            font-size: 16px;
            font-weight: bold;
            color: #111827;
            margin-top: 4px;
        }

        .kpi-sub {
            font-size: 9px;
            color: #6b7280;
            margin-top: 2px;
        }

        table.data {
            width: 100%;
            border-collapse: collapse;
            margin-top: 4px;
        }

        table.data th,
        table.data td {
            border: 1px solid #d1d5db;
            padding: 4px 6px;
            text-align: left;
            font-size: 9px;
        }

        table.data th {
            background: #1976D2;
            color: #fff;
            font-weight: bold;
        }

        table.data tr:nth-child(even) td {
            background: #f9fafb;
        }

        .num {
            text-align: right;
        }

        .pct-bar {
            display: inline-block;
            width: 50px;
            height: 6px;
            background: #e5e7eb;
            border-radius: 3px;
            position: relative;
            vertical-align: middle;
            margin-right: 4px;
        }

        .pct-fill {
            position: absolute;
            top: 0;
            left: 0;
            height: 100%;
            background: #17C964;
            border-radius: 3px;
        }

        .pct-fill.warning { background: #F5A524; }
        .pct-fill.danger { background: #F31260; }

        .highlight-box {
            background: #f0f9ff;
            border-left: 3px solid #1976D2;
            padding: 6px 10px;
            margin: 4px 0;
            font-size: 10px;
        }

        .chart-image {
            max-width: 100%;
            height: auto;
            margin: 8px 0;
        }

        .chart-grid {
            width: 100%;
            border-collapse: collapse;
        }

        .chart-grid td {
            width: 50%;
            padding: 4px;
            vertical-align: top;
        }

        .footer {
            text-align: center;
            font-size: 9px;
            color: #9ca3af;
            margin-top: 16px;
            border-top: 1px solid #e5e7eb;
            padding-top: 6px;
        }
    </style>
</head>

<body>
    <div class="header">
        <h1>{{ $title }}</h1>
        <div class="meta">
            <strong>Period:</strong> {{ $periodLabel }} &nbsp;&middot;&nbsp;
            <strong>Generated:</strong> {{ $generatedOn }}
        </div>
    </div>

    @php
        $kpi = $analytics['kpi'] ?? [];
        $highlights = $analytics['highlights'] ?? [];
    @endphp

    {{-- KPI Cards Row --}}
    <div class="section-title">Key Performance Indicators</div>
    <table class="kpi-grid">
        <tr>
            <td>
                <div class="kpi-label">Total Works</div>
                <div class="kpi-value">{{ number_format($kpi['totalWorks'] ?? 0) }}</div>
                <div class="kpi-sub">All logged works in period</div>
            </td>
            <td>
                <div class="kpi-label">Completed</div>
                <div class="kpi-value" style="color: #17C964;">{{ number_format($kpi['completed'] ?? 0) }}</div>
                <div class="kpi-sub">{{ $kpi['completionRate'] ?? 0 }}% completion rate</div>
            </td>
            <td>
                <div class="kpi-label">Pending</div>
                <div class="kpi-value" style="color: #F5A524;">{{ number_format($kpi['pending'] ?? 0) }}</div>
                <div class="kpi-sub">In progress / new</div>
            </td>
            <td>
                <div class="kpi-label">RFI Submissions</div>
                <div class="kpi-value" style="color: #0070F0;">{{ number_format($kpi['rfiSubmissions'] ?? 0) }}</div>
                <div class="kpi-sub">{{ $kpi['rfiRate'] ?? 0 }}% RFI rate</div>
            </td>
        </tr>
        <tr>
            <td>
                <div class="kpi-label">Avg Daily Works</div>
                <div class="kpi-value">{{ $kpi['avgDailyWorks'] ?? 0 }}</div>
                <div class="kpi-sub">Throughput per active day</div>
            </td>
            <td>
                <div class="kpi-label">Total Resubmissions</div>
                <div class="kpi-value" style="color: #F31260;">{{ number_format($kpi['totalResubmissions'] ?? 0) }}</div>
                <div class="kpi-sub">{{ $kpi['worksWithResubmissions'] ?? 0 }} works affected</div>
            </td>
            <td>
                <div class="kpi-label">Trend Direction</div>
                @php $td = $kpi['trendDirection'] ?? 0; @endphp
                <div class="kpi-value" style="color: {{ $td >= 0 ? '#17C964' : '#F31260' }};">
                    {{ $td > 0 ? '+' : '' }}{{ $td }}%
                </div>
                <div class="kpi-sub">vs first half of period</div>
            </td>
            <td>
                <div class="kpi-label">Most Common Type</div>
                <div class="kpi-value">{{ $highlights['mostCommonType']['name'] ?? '—' }}</div>
                <div class="kpi-sub">{{ $highlights['mostCommonType']['value'] ?? 0 }} works</div>
            </td>
        </tr>
    </table>

    {{-- Highlights --}}
    <div class="section-title">Highlights</div>
    @if(($highlights['bestDay'] ?? null))
        <div class="highlight-box">
            <strong>Best Day:</strong> {{ $highlights['bestDay']['date'] }} —
            {{ $highlights['bestDay']['completionRate'] }}% completion ({{ $highlights['bestDay']['completed'] }}/{{ $highlights['bestDay']['total'] }})
        </div>
    @endif
    @if(($highlights['busiestDay'] ?? null))
        <div class="highlight-box">
            <strong>Busiest Day:</strong> {{ $highlights['busiestDay']['date'] }} —
            {{ $highlights['busiestDay']['total'] }} works logged
        </div>
    @endif
    @if(($highlights['topIncharge'] ?? null))
        <div class="highlight-box">
            <strong>Top Incharge:</strong> {{ $highlights['topIncharge']['incharge'] }} —
            {{ $highlights['topIncharge']['completionRate'] }}% completion
            ({{ $highlights['topIncharge']['completed'] }}/{{ $highlights['topIncharge']['total'] }})
        </div>
    @endif

    {{-- Embedded chart images (optional) --}}
    @if(!empty($chartImages))
        <div class="section-title">Analytics Charts</div>
        <table class="chart-grid">
            @php $cols = 0; @endphp
            @foreach($chartImages as $label => $dataUri)
                @if($cols % 2 === 0)
                    <tr>
                @endif
                <td>
                    <strong style="font-size: 10px;">{{ ucwords(str_replace('_', ' ', $label)) }}</strong>
                    <img class="chart-image" src="{{ $dataUri }}" alt="{{ $label }}">
                </td>
                @php $cols++; @endphp
                @if($cols % 2 === 0)
                    </tr>
                @endif
            @endforeach
            @if($cols % 2 !== 0)
                <td></td></tr>
            @endif
        </table>
    @endif

    {{-- Daily Breakdown Table --}}
    <div class="section-title">Daily Breakdown</div>
    <table class="data">
        <thead>
            <tr>
                <th>Date</th>
                <th class="num">Total</th>
                <th class="num">Embankment</th>
                <th class="num">Structure</th>
                <th class="num">Pavement</th>
                <th class="num">Resubmissions</th>
                <th class="num">Completed</th>
                <th class="num">Pending</th>
                <th class="num">Completion %</th>
                <th class="num">RFI</th>
            </tr>
        </thead>
        <tbody>
            @forelse($summaries as $s)
                @php
                    $pct = $s['completionPercentage'] ?? 0;
                    $pctClass = $pct >= 75 ? '' : ($pct >= 50 ? 'warning' : 'danger');
                @endphp
                <tr>
                    <td>{{ \Carbon\Carbon::parse($s['date'])->format('M d, Y') }}</td>
                    <td class="num">{{ $s['totalDailyWorks'] ?? 0 }}</td>
                    <td class="num">{{ $s['embankment'] ?? 0 }}</td>
                    <td class="num">{{ $s['structure'] ?? 0 }}</td>
                    <td class="num">{{ $s['pavement'] ?? 0 }}</td>
                    <td class="num">{{ $s['resubmissions'] ?? 0 }}</td>
                    <td class="num" style="color: #17C964; font-weight: bold;">{{ $s['completed'] ?? 0 }}</td>
                    <td class="num" style="color: #F31260;">{{ $s['pending'] ?? 0 }}</td>
                    <td class="num">
                        <span class="pct-bar"><span class="pct-fill {{ $pctClass }}" style="width: {{ min(100, $pct) }}%;"></span></span>
                        {{ $pct }}%
                    </td>
                    <td class="num">{{ $s['rfiSubmissions'] ?? 0 }}</td>
                </tr>
            @empty
                <tr><td colspan="10" style="text-align:center; padding: 12px;">No data for selected period</td></tr>
            @endforelse
        </tbody>
    </table>

    {{-- Per-Incharge Performance --}}
    @if(!empty($analytics['inchargePerformance']))
        <div class="section-title">Per-Incharge Performance</div>
        <table class="data">
            <thead>
                <tr>
                    <th>Incharge</th>
                    <th class="num">Total</th>
                    <th class="num">Completed</th>
                    <th class="num">Pending</th>
                    <th class="num">RFI</th>
                    <th class="num">Completion %</th>
                </tr>
            </thead>
            <tbody>
                @foreach($analytics['inchargePerformance'] as $ic)
                    @php
                        $pct = $ic['completionRate'] ?? 0;
                        $pctClass = $pct >= 75 ? '' : ($pct >= 50 ? 'warning' : 'danger');
                    @endphp
                    <tr>
                        <td>{{ $ic['incharge'] }}</td>
                        <td class="num">{{ $ic['total'] }}</td>
                        <td class="num" style="color: #17C964;">{{ $ic['completed'] }}</td>
                        <td class="num" style="color: #F31260;">{{ $ic['pending'] }}</td>
                        <td class="num">{{ $ic['rfiSubmissions'] }}</td>
                        <td class="num">
                            <span class="pct-bar"><span class="pct-fill {{ $pctClass }}" style="width: {{ min(100, $pct) }}%;"></span></span>
                            {{ $pct }}%
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <div class="footer">
        Aero Enterprise Suite &middot; Daily Work Summary Report &middot; Page <span class="pagenum"></span>
    </div>
</body>

</html>
