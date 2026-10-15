{{-- Note line + the framed table of a tabular report. Needs: note, headings, colPct, rows, leftAligned, empty. --}}
@if ($note)
    <div style="text-align:center; font-size:9pt; padding:2pt; border-left:1.5pt solid #000; border-right:1.5pt solid #000; border-bottom:0.5pt solid #000;">{{ $note }}</div>
@endif
<table class="rep" style="border-left:1.5pt solid #000; border-right:1.5pt solid #000;">
    <thead>
        <tr>@foreach ($headings as $i => $h)<th style="width: {{ $colPct[$i] }}%">{!! \App\Exports\Register\RegisterPdfGrid::symbolize((string) $h) !!}</th>@endforeach</tr>
    </thead>
    <tbody>
        @forelse ($rows as $row)
            <tr>@foreach (array_values($row) as $i => $cell)<td style="text-align: {{ in_array($i + 1, $leftAligned, true) ? 'left' : 'center' }}">{!! \App\Exports\Register\RegisterPdfGrid::symbolize((string) ($cell ?? '')) !!}</td>@endforeach</tr>
        @empty
            <tr><td colspan="{{ count($headings) }}" style="text-align:center">{{ $empty }}</td></tr>
        @endforelse
    </tbody>
</table>
