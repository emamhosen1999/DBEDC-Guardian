<?php

namespace Tests\Feature\DailyWork;

use App\Models\DailyWork;
use App\Models\DailyWorkImportBatch;
use App\Models\DailyWorkRevision;
use App\Models\DailyWorkSummary;
use App\Models\User;
use Illuminate\Http\UploadedFile;

/** The import wizard: row-level preview, commit of what was previewed, idempotent re-import, provenance, error report, reversal. */
class DailyWorkImportWizardTest extends DailyWorkTestCase
{
    private array $p = [];

    private User $importer;

    protected function setUp(): void
    {
        parent::setUp();
        $this->p = $this->phases();
        $this->importer = $this->viewer(['view', 'create', 'import']);
    }

    private function csv(array $rows, string $name = 'works.csv'): UploadedFile
    {
        $lines = array_map(fn (array $r): string => implode(',', array_map(fn ($c) => '"'.str_replace('"', '""', (string) $c).'"', $r)), $rows);

        return UploadedFile::fake()->createWithContent($name, implode("\n", $lines)."\n");
    }

    private function good(string $date = '2026-05-04'): array
    {
        return [
            [$date, 'RFI-W1', 'Pavement', 'Base course check', 'K05+100-K05+200', 'TR-L', '', '10:00'],
            [$date, 'RFI-W2', 'Structure', 'Culvert check', 'K12+000', 'SR-R', '', '11:00'],
            [$date, 'RFI-W3', 'Embankment', 'Fill level', 'K30+250', 'TR-R', '2nd', '14:00'],
        ];
    }

    private function preview(array $rows, ?string $name = null)
    {
        return $this->actingAs($this->importer)->postJson(route('dailyWorks.previewImport'), ['file' => $this->csv($rows, $name ?? 'works.csv')]);
    }

    private function commit(string $token, array $extra = [])
    {
        $r = $this->actingAs($this->importer)->postJson(route('dailyWorks.import'), ['token' => $token] + $extra);
        if ($r->status() >= 500) {
            fwrite(STDERR, 'DBG '.substr(json_encode($r->json()), 0, 700)."\n");
        }

        return $r;
    }

    public function test_the_preview_says_for_every_row_what_would_happen_and_writes_no_work(): void
    {
        $rows = array_merge($this->good(), [
            ['2026-05-04', 'RFI-W4', 'Roadwork', 'Bad type', 'K01+100', 'TR-L', '', ''],
            ['2026-05-04', 'RFI-W5', 'Pavement', 'Not a chainage', 'Main gate', 'TR-L', '', ''],
            ['2026-05-04', 'RFI-W6', 'Pavement', 'Outside every jurisdiction', 'K49+500', 'TR-L', '', ''],
            ['2026-05-04', 'RFI-W3', 'Embankment', 'Same RFI again in the sheet', 'K30+250', 'TR-R', '', ''],
            ['2026-05-04', 'RFI-W7', 'pavement', 'Lower-case type', 'K27', 'TR-L', '', ''],
        ]);
        $res = $this->preview($rows)->assertOk();
        $a = $res->json('summary.analysis');
        $byRfi = collect($a['sheets'][0]['rows'])->keyBy(fn ($r) => $r['rfi_number'].'#'.$r['row']);

        $this->assertSame('valid', $byRfi['RFI-W1#1']['level']);
        $this->assertSame('PH-1', $byRfi['RFI-W1#1']['jurisdiction']['code']);
        $this->assertSame(5100, $byRfi['RFI-W1#1']['span']['from']);
        $this->assertSame($this->p['people'][0]->employee_id, $byRfi['RFI-W1#1']['incharge']['id']);
        $this->assertSame('PH-3', $byRfi['RFI-W3#3']['jurisdiction']['code']);
        $this->assertSame('error', $byRfi['RFI-W4#4']['level']);
        $this->assertStringContainsString('Unknown work type', $byRfi['RFI-W4#4']['issues'][0]['message']);
        $this->assertStringContainsString('not a readable chainage', $byRfi['RFI-W5#5']['issues'][0]['message']);
        $this->assertStringContainsString('No jurisdiction covers K49+500', $byRfi['RFI-W6#6']['issues'][0]['message']);
        $this->assertStringContainsString('more than once', $byRfi['RFI-W3#7']['issues'][0]['message']);
        $this->assertSame('warning', $byRfi['RFI-W7#8']['level'], 'a km-only location and a lower-case type are warnings, never errors');
        $this->assertSame('Pavement', $byRfi['RFI-W7#8']['type']);
        $this->assertSame(27000, $byRfi['RFI-W7#8']['span']['from']);
        $this->assertSame(['rows' => 8, 'valid' => 3, 'warning' => 1, 'error' => 4], array_intersect_key($a['counts'], array_flip(['rows', 'valid', 'warning', 'error'])));
        $this->assertSame(0, DailyWork::count(), 'a preview writes no work');
        $this->assertSame('previewed', DailyWorkImportBatch::first()->status);
        $this->assertSame(64, strlen(DailyWorkImportBatch::first()->file_sha256));
    }

    public function test_commit_is_all_or_nothing_unless_the_partial_import_is_confirmed(): void
    {
        $rows = array_merge($this->good(), [['2026-05-04', 'RFI-W5', 'Pavement', 'Not a chainage', 'Main gate', 'TR-L', '', '']]);
        $token = $this->preview($rows)->json('summary.token');
        $this->commit($token)->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(0, DailyWork::count());

        $done = $this->commit($token, ['allow_partial' => true])->assertOk();
        $this->assertSame(3, $done->json('batch.rows_created'));
        $this->assertSame(1, $done->json('batch.rows_rejected'));
        $this->assertSame('RFI-W5', $done->json('rejected.0.rfi_number'));
        $this->assertSame(3, DailyWork::count());
    }

    public function test_every_imported_work_carries_its_chainage_jurisdiction_in_charge_batch_and_history(): void
    {
        $token = $this->preview($this->good())->json('summary.token');
        $this->commit($token)->assertOk();
        $batch = DailyWorkImportBatch::first();
        $w = DailyWork::where('number', 'RFI-W1')->first();

        $this->assertSame([5100, 5200, $this->p['one']->id, $batch->id], [(int) $w->start_m, (int) $w->end_m, (int) $w->jurisdiction_id, (int) $w->import_batch_id]);
        $this->assertSame($this->p['people'][0]->employee_id, $w->incharge);
        $this->assertSame('new', $w->status);
        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->first();
        $this->assertSame(['created', 'import', $batch->id], [$rev->event, $rev->source, $rev->import_batch_id]);
        $this->assertSame('committed', $batch->status);
        $this->assertSame(3, DailyWorkSummary::sum('totalDailyWorks'));
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'daily-works.import.committed', 'subject_id' => (string) $batch->id]);
    }

    public function test_importing_the_same_file_again_is_harmless(): void
    {
        $first = $this->preview($this->good())->json('summary.token');
        $this->commit($first)->assertOk();
        $revisions = DailyWorkRevision::count();

        $again = $this->preview($this->good())->assertOk();
        $this->assertNotNull($again->json('summary.already_imported'), 'the same file is recognised by its hash');
        $this->assertSame(3, $again->json('summary.analysis.counts.skip'));
        $this->commit($again->json('summary.token'))->assertStatus(422)->assertJsonValidationErrors('file');
        $this->assertSame(3, DailyWork::count());
        $this->assertSame($revisions, DailyWorkRevision::count(), 'no revision, no duplicate, no resubmission count');
        $this->assertSame(0, (int) DailyWork::where('number', 'RFI-W1')->value('resubmission_count'));
    }

    public function test_a_later_sheet_with_the_same_rfi_is_its_next_numbered_resubmission(): void
    {
        $this->commit($this->preview($this->good())->json('summary.token'))->assertOk();
        $later = [['2026-05-11', 'RFI-W1', 'Pavement', 'Base course check (resubmitted)', 'K05+100-K05+250', 'TR-L', '', '09:00']];
        $p = $this->preview($later, 'later.csv')->assertOk();
        $row = $p->json('summary.analysis.sheets.0.rows.0');
        $this->assertSame('resubmit', $row['action']);
        $this->assertSame('warning', $row['level']);
        $this->commit($p->json('summary.token'))->assertOk()->assertJsonPath('batch.rows_resubmitted', 1);

        $w = DailyWork::where('number', 'RFI-W1')->first();
        $this->assertSame(1, DailyWork::where('number', 'RFI-W1')->count(), 'the same work, not a copy');
        $this->assertSame([1, '2026-05-11', 5250], [(int) $w->resubmission_count, $w->date->toDateString(), (int) $w->end_m]);
        $rev = DailyWorkRevision::where('daily_work_id', $w->id)->orderByDesc('revision_no')->first();
        $this->assertContains('resubmitted', $rev->tags);
        $this->assertSame('2026-05-04', collect($rev->changes)->firstWhere('field', 'date')['before'], 'the revision keeps the old date');
    }

    public function test_a_completed_rfi_is_skipped_and_never_changes_hands(): void
    {
        $this->commit($this->preview($this->good())->json('summary.token'))->assertOk();
        \DB::table('daily_works')->where('number', 'RFI-W1')->update(['status' => 'completed', 'inspection_result' => 'pass']);
        $p = $this->preview([['2026-05-12', 'RFI-W1', 'Pavement', 'again', 'K05+100', 'TR-L', '', '']], 'again.csv')->assertOk();
        $this->assertSame('skip', $p->json('summary.analysis.sheets.0.rows.0.action'));
        $this->commit($p->json('summary.token'))->assertStatus(422);
    }

    public function test_the_error_report_lists_the_rejected_rows_with_their_reasons(): void
    {
        $rows = array_merge($this->good(), [['2026-05-04', 'RFI-W5', 'Pavement', 'Not a chainage', 'Main gate', 'TR-L', '', '']]);
        $this->commit($this->preview($rows)->json('summary.token'), ['allow_partial' => true])->assertOk();
        $batch = DailyWorkImportBatch::first();
        $res = $this->actingAs($this->importer)->get(route('dailyWorks.imports.errors', $batch->id))->assertOk();
        $csv = $res->streamedContent();
        $this->assertStringContainsString('Why it was rejected', $csv);
        $this->assertStringContainsString('RFI-W5', $csv);
        $this->assertStringContainsString('not a readable chainage', $csv);
    }

    public function test_reversing_a_batch_is_audited_soft_and_needs_its_own_permission(): void
    {
        $this->commit($this->preview($this->good())->json('summary.token'))->assertOk();
        $batch = DailyWorkImportBatch::first();

        $this->actingAs($this->importer)->postJson(route('dailyWorks.imports.reverse', $batch->id), ['reason' => 'Wrong file'])->assertForbidden();
        $reverser = $this->viewer(['view', 'import', 'import.reverse', 'restore']);
        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $batch->id), [])->assertStatus(422)->assertJsonValidationErrors('reason');
        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $batch->id), ['reason' => 'Wrong file uploaded'])->assertOk();

        $this->assertSame(0, DailyWork::count());
        $this->assertSame(3, DailyWork::onlyTrashed()->count(), 'soft: the works can be restored');
        $this->assertSame('reversed', $batch->fresh()->status);
        $this->assertSame(0, (int) DailyWorkSummary::sum('totalDailyWorks'), 'the summaries the import added are taken back');
        $this->assertSame('deleted', DailyWorkRevision::where('daily_work_id', DailyWork::withTrashed()->first()->id)->orderByDesc('revision_no')->first()->event);
        $this->assertStringContainsString('Reversal of import batch', DailyWorkRevision::orderByDesc('id')->first()->reason);
        $this->assertDatabaseHas('access_audit_logs', ['action' => 'daily-works.import.reversed', 'subject_id' => (string) $batch->id]);
        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $batch->id), ['reason' => 'Twice'])->assertStatus(422);

        $w = DailyWork::onlyTrashed()->first();
        $this->actingAs($this->dwAdmin())->postJson(route('dailyWorks.restore', $w->id))->assertOk();
    }

    public function test_a_work_worked_on_since_the_import_is_a_conflict_and_blocks_the_reversal_unless_skipped(): void
    {
        $this->commit($this->preview($this->good())->json('summary.token'))->assertOk();
        $batch = DailyWorkImportBatch::first();
        $busy = DailyWork::where('number', 'RFI-W2')->first();
        $this->actingAs($this->dwAdmin());
        $busy->description = 'Edited after the import';
        $busy->save();

        $reverser = $this->viewer(['view', 'import', 'import.reverse']);
        $plan = $this->actingAs($reverser)->getJson(route('dailyWorks.imports.show', $batch->id))->assertOk()->json('plan');
        $this->assertSame(['RFI-W2'], array_column($plan['conflicts'], 'number'));
        $this->assertSame(2, count($plan['delete']));

        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $batch->id), ['reason' => 'Wrong file uploaded'])->assertStatus(422)->assertJsonValidationErrors('conflicts');
        $this->assertSame(3, DailyWork::count());
        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $batch->id), ['reason' => 'Wrong file uploaded', 'skip_conflicts' => true])->assertOk()->assertJsonPath('result.skipped', 1);
        $this->assertSame(['RFI-W2'], DailyWork::pluck('number')->all());
    }

    public function test_reversing_a_resubmission_gives_the_work_back_the_values_it_had(): void
    {
        $this->commit($this->preview($this->good())->json('summary.token'))->assertOk();
        $later = [['2026-05-11', 'RFI-W1', 'Pavement', 'Resubmitted description', 'K05+100-K05+250', 'TR-L', '', '09:00']];
        $this->commit($this->preview($later, 'later.csv')->json('summary.token'))->assertOk();
        $second = DailyWorkImportBatch::orderByDesc('id')->first();

        $reverser = $this->viewer(['view', 'import', 'import.reverse']);
        $this->actingAs($reverser)->postJson(route('dailyWorks.imports.reverse', $second->id), ['reason' => 'Resubmission was a mistake'])->assertOk()->assertJsonPath('result.reverted', 1);
        $w = DailyWork::where('number', 'RFI-W1')->first();
        $this->assertSame([0, '2026-05-04', 'Base course check'], [(int) $w->resubmission_count, $w->date->toDateString(), $w->description]);
    }

    public function test_import_routes_need_the_import_permission_and_the_old_response_shape_is_kept(): void
    {
        $this->actingAs($this->viewer(['view', 'create']))->postJson(route('dailyWorks.previewImport'), ['file' => $this->csv($this->good())])->assertForbidden();
        $token = $this->preview($this->good())->json('summary.token');
        $this->commit($token)->assertOk()->assertJsonStructure(['message', 'results' => [['sheet', 'date', 'summaries', 'processed_count']], 'batch', 'rejected']);
        $legacy = $this->preview($this->good('2026-06-01'), 'legacy.csv')->json('summary.sheets.0');
        $this->assertArrayHasKey('by_incharge', $legacy);
        $this->assertSame(3, $legacy['stats']['total']);
    }
}
