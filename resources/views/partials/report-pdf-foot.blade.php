{{-- Symbol key (when $withKey) and the Recorder / Authorized by signature row. --}}
@if (! empty($withKey))
    <div class="rep-key" style="border-top:0">{!! \App\Exports\Register\RegisterPdfGrid::symbolize(\App\Support\Attendance\AttendanceSymbol::keyText()) !!}</div>
@endif
<table class="rep-sign">
    <tr>
        <td style="width:50%; border-right:1.5pt solid #000; border-left:1.5pt solid #000; border-bottom:1.5pt solid #000;">{!! nl2br(e(\App\Exports\Register\RegisterFormat::recorderText())) !!}</td>
        <td style="width:50%; border-right:1.5pt solid #000; border-bottom:1.5pt solid #000;">{!! nl2br(e(\App\Exports\Register\RegisterFormat::authorizedText())) !!}</td>
    </tr>
</table>
