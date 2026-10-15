{{-- Company / report title / period rows, framed like the register. --}}
<table style="width:100%; border-collapse:collapse; border:1.5pt solid #000;">
    <tr><td style="text-align:center; font-weight:bold; font-size:13pt; height:24pt; border-bottom:0.5pt solid #000;">{{ \App\Exports\Register\RegisterFormat::COMPANY }}</td></tr>
    <tr><td style="text-align:center; font-weight:bold; font-size:10pt; height:18pt; border-bottom:0.5pt solid #000;">{!! \App\Exports\Register\RegisterPdfGrid::symbolize($reportTitle) !!}</td></tr>
    <tr><td style="text-align:center; font-size:9pt; height:14pt;">{{ \App\Exports\Register\RegisterFormat::period($from, $to) }}</td></tr>
</table>
