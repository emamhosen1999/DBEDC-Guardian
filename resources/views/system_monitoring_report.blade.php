<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>DBEDC Guardian System Report</title>
    <style>
        @page { margin: 28px; }
        body { color: #172033; font-family: DejaVu Sans, sans-serif; font-size: 10px; line-height: 1.45; }
        h1 { color: #173b68; font-size: 20px; margin: 0 0 4px; }
        h2 { border-bottom: 1px solid #ccd7e6; color: #245487; font-size: 13px; margin: 18px 0 8px; padding-bottom: 4px; }
        .meta { color: #5c6b7c; margin-bottom: 14px; }
        pre { background: #f3f6fa; border: 1px solid #d9e1eb; border-radius: 4px; font-family: DejaVu Sans Mono, monospace; font-size: 8px; padding: 8px; white-space: pre-wrap; word-break: break-word; }
        .section { page-break-inside: avoid; }
    </style>
</head>
<body>
    <h1>DBEDC Guardian System Report</h1>
    <div class="meta">
        Generated {{ $generatedAt->format('Y-m-d H:i:s T') }}
        @if($generatedBy) by {{ $generatedBy }} @endif
    </div>

    @foreach($overview as $section => $data)
        <div class="section">
            <h2>{{ ucwords(str_replace('_', ' ', $section)) }}</h2>
            <pre>{{ json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) }}</pre>
        </div>
    @endforeach
</body>
</html>
