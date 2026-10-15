<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>Attendance Register</title>
<style>
@include('partials.report-pdf-base')
    .sheet { page-break-after: always; }
    .sheet.last { page-break-after: avoid; }
    table.reg { border-collapse: collapse; }
    table.reg td { padding: 0; overflow: hidden; font-family: 'RegNarrow', sans-serif; line-height: 1.05; }
    /* The avatar is the name cell's background: a square exactly the row height, flush left; the text is padded clear of it. */
</style>
</head>
<body>
@foreach ($sheets as $i => $sheet)
    @php
        $g = $sheet['grid'];
    @endphp
    <div class="sheet {{ $loop->last ? 'last' : '' }}">
        <table class="reg" style="width: {{ $g['widthPt'] }}pt">
            <colgroup>
                @foreach ($g['cols'] as $w)<col style="width: {{ $w }}pt">@endforeach
            </colgroup>
            {{-- dompdf sizes a fixed table from its first row; the title rows are merged, so a zero-height row carries the widths. --}}
            <tr style="height: 0">
                @foreach ($g['cols'] as $w)<td style="width: {{ $w }}pt; height: 0; padding: 0; border: 0; font-size: 0; line-height: 0"></td>@endforeach
            </tr>
            @foreach ($g['rows'] as $row)
                <tr style="height: {{ $row['height'] }}pt">
                    @foreach ($row['cells'] as $c)
                        @php
                            $style = 'width:'.$c['width'].'pt;border-left:'.$c['border']['l'].';border-right:'.$c['border']['r'].';border-top:'.$c['border']['t'].';border-bottom:'.$c['border']['b'].';'
                                .'font-size:'.$c['size'].'pt;font-weight:'.($c['bold'] ? 'bold' : 'normal').';color:'.$c['color'].';text-align:'.$c['align'].';vertical-align:'.$c['valign'].';'
                                .($c['fill'] ? 'background-color:'.$c['fill'].';' : '')
                                .('white-space:'.($c['wrap'] ? 'pre-wrap' : 'pre').';')
                                .($c['avatar'] ? 'background-image:url(\''.$c['avatar'].'\');background-repeat:no-repeat;background-position:0 0;background-size:'.$c['rowPt'].'pt '.$c['rowPt'].'pt;padding-left:'.($c['rowPt'] + 2).'pt;' : ($c['indent'] ? 'padding-left:'.($c['indent'] * 4).'pt;' : ''));
                        @endphp
                        <td @if ($c['colspan'] > 1) colspan="{{ $c['colspan'] }}" @endif @if ($c['rowspan'] > 1) rowspan="{{ $c['rowspan'] }}" @endif style="{{ $style }}">{!! \App\Exports\Register\RegisterPdfGrid::symbolize($c['text'], false) !!}</td>
                    @endforeach
                </tr>
            @endforeach
        </table>
    </div>
@endforeach
@isset($summaryReport)
    <div style="page-break-before: always">
        @include('partials.report-pdf-head', ['reportTitle' => $summaryReport['title'], 'from' => $summaryReport['from'], 'to' => $summaryReport['to']])
        @include('partials.report-pdf-table', $summaryReport['table'])
        @include('partials.report-pdf-foot', ['withKey' => false])
    </div>
@endisset
</body>
</html>
