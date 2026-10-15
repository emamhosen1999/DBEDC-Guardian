<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>{{ $reportTitle }}</title>
<style>
@include('partials.report-pdf-base')
    table.rep { table-layout: auto; }
    table.rep thead { display: table-header-group; }
    table.rep tr { page-break-inside: avoid; }
</style>
</head>
<body>
@include('partials.report-pdf-head')
@include('partials.report-pdf-table')
@include('partials.report-pdf-foot')
</body>
</html>
