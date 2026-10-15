<?php

namespace App\Http\Controllers;

use App\Models\Jurisdiction;
use App\Services\DailyWork\DailyWorkRegisterService;
use App\Services\DailyWork\DailyWorkWorkflow;
use App\Services\Jurisdiction\JurisdictionTeamService;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * The Daily Inspection Report: one date (and optionally one jurisdiction), A4 portrait, the viewer's own scope only.
 * Works list, status totals, the team as it stood on that date, and a signatures block. Real records only.
 */
class DailyWorkReportController extends Controller
{
    private const LIST_LIMIT = 400;

    public function __construct(private readonly DailyWorkRegisterService $register, private readonly JurisdictionTeamService $team) {}

    public function inspection(Request $request): Response
    {
        $data = $request->validate(['date' => ['required', 'date_format:Y-m-d'], 'jurisdiction' => ['nullable', 'integer', 'exists:jurisdictions,id']]);
        $date = $data['date'];
        $jurisdiction = isset($data['jurisdiction']) ? Jurisdiction::withTrashed()->find($data['jurisdiction']) : null;

        $base = $this->register->scopedQuery($request->user())->where('date', '>=', $date)->where('date', '<', date('Y-m-d', strtotime($date.' +1 day')));
        if ($jurisdiction) {
            $base->where('jurisdiction_id', $jurisdiction->id);
        }
        $total = (clone $base)->count();
        $works = (clone $base)->with(['inchargeUser:employee_id,name'])->orderBy('start_m')->orderBy('number')->limit(self::LIST_LIMIT)->get();
        $totals = (clone $base)->toBase()->selectRaw("case when status = 'complete' then 'completed' else status end as k, count(*) as n")->groupBy('k')->pluck('n', 'k');
        $results = (clone $base)->toBase()->whereNotNull('inspection_result')->selectRaw('inspection_result as k, count(*) as n')->groupBy('k')->pluck('n', 'k');
        $codes = Jurisdiction::withTrashed()->whereIn('id', $works->pluck('jurisdiction_id')->filter()->unique())->pluck('code', 'id');

        $team = $jurisdiction ? $this->team->teamAsOf($jurisdiction, $date)->map(fn ($m): array => [
            'employee_id' => $m->employee?->employee_id, 'name' => $m->employee?->name, 'designation' => $m->employee?->designation?->title, 'role' => $m->role,
        ])->values()->all() : [];

        return Inertia::render('Project/DailyWorks/InspectionReportPrint', [
            'date' => $date,
            'jurisdiction' => $jurisdiction ? ['id' => $jurisdiction->id, 'code' => $jurisdiction->code, 'name' => $jurisdiction->location, 'start_chainage' => $jurisdiction->start_chainage, 'end_chainage' => $jurisdiction->end_chainage] : null,
            'works' => $works->map(fn ($w): array => [
                'id' => $w->id, 'number' => $w->number, 'type' => $w->type, 'location' => $w->location, 'side' => $w->side, 'description' => $w->description,
                'status' => DailyWorkWorkflow::normalise($w->status), 'result' => $w->inspection_result, 'incharge' => $w->inchargeUser?->name ?? $w->incharge,
                'jurisdiction' => $codes[$w->jurisdiction_id] ?? null,
            ])->all(),
            'total' => $total,
            'truncated' => max(0, $total - self::LIST_LIMIT),
            'status_totals' => $totals->all(),
            'result_totals' => $results->all(),
            'team' => $team,
            'title_block' => [
                'project' => config('app.name'), 'title' => 'Daily Inspection Report', 'generated_at' => now('Asia/Dhaka')->format('d M Y, H:i').' (Asia/Dhaka)',
                'prepared_by' => $request->user()->name, 'revision' => 'DIR-'.str_replace('-', '', $date).($jurisdiction ? '-'.$jurisdiction->code : ''),
            ],
        ]);
    }
}
