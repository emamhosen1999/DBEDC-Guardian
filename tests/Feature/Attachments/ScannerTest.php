<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachments\StagedAttachment;
use App\Models\HRM\Leave;
use App\Models\HRM\LeaveSetting;
use App\Models\User;
use App\Services\Attachments\AttachmentAccess;
use App\Services\Attachments\AttachmentClarityService;
use App\Services\Attachments\AttachmentRejected;
use App\Services\Attachments\AttachmentService;
use App\Services\Attachments\PdfInspector;
use App\Services\Attachments\Scanner\DocumentScanner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Attachments\MakesAttachments;
use Tests\Support\Attachments\SyntheticDocuments as Docs;
use Tests\TestCase;

/** The server-side document scanner on synthetic photos of a printed page lying on a textured desk. */
class ScannerTest extends TestCase
{
    use MakesAttachments, RefreshDatabase;

    private const UPRIGHT = [[260, 300], [1700, 330], [1660, 2300], [240, 2260]];

    private const TILTED = [[500, 250], [1800, 450], [1550, 2350], [200, 2100]];

    protected function setUp(): void
    {
        parent::setUp();
        $this->raiseMemoryForImages();
    }

    private function photo(array $quad, float $light = 1.0): array
    {
        return Docs::photo(Docs::page(), 2000, 2500, $quad, $light);
    }

    private function maxCornerError(array $found, array $truth): float
    {
        $ordered = (new DocumentScanner)->orderCorners($truth);
        $worst = 0.0;
        foreach ($found as $i => $c) {
            $worst = max($worst, hypot($c[0] - $ordered[$i][0], $c[1] - $ordered[$i][1]));
        }

        return $worst;
    }

    public function test_corners_are_found_within_tolerance_at_several_angles_and_lighting_levels(): void
    {
        $scanner = new DocumentScanner;
        foreach ([[self::UPRIGHT, 1.0, 60], [self::TILTED, 1.0, 60], [self::TILTED, 0.6, 110]] as [$quad, $light, $tolerance]) {
            $photo = $this->photo($quad, $light);
            $found = $scanner->detect($photo['image']);
            $this->assertGreaterThanOrEqual(0.6, $found['confidence'], 'a clear page photo is detected with confidence');
            $this->assertLessThanOrEqual($tolerance, $this->maxCornerError($found['corners'], $photo['quad']), 'corner error in px');
        }
    }

    public function test_the_flattened_page_is_upright_and_passes_the_clarity_check_in_every_mode(): void
    {
        $scanner = new DocumentScanner;
        $clarity = app(AttachmentClarityService::class);
        $photo = $this->photo(self::TILTED);
        $found = $scanner->detect($photo['image']);

        foreach (['color', 'grey', 'bw'] as $mode) {
            $flat = $scanner->flatten($photo['image'], $found['corners'], $mode);
            $ratio = imagesy($flat) / imagesx($flat);
            $this->assertEqualsWithDelta(1754 / 1240, $ratio, 0.12, "{$mode}: page aspect restored");
            $result = $clarity->checkImage($flat);
            $this->assertTrue($result->passed, "{$mode}: ".$result->code);
        }
    }

    public function test_user_adjusted_corners_are_honoured(): void
    {
        $this->fakeAttachments();
        $user = User::factory()->create();
        $photo = $this->photo(self::UPRIGHT);
        $row = app(AttachmentService::class)->scanPage($this->upload(Docs::jpeg($photo['image'], 85), 'photo.jpg'), (string) $user->employee_id);

        // The user drags the corners to the top half of the page only.
        $half = [[260, 300], [1700, 330], [1680, 1300], [250, 1280]];
        $row = app(AttachmentService::class)->rescan($row, $half, 'grey');

        $this->assertSame('grey', $row->mode);
        $this->assertSame(1.0, $row->confidence, 'corners placed by the user are trusted');
        $this->assertEqualsWithDelta(260, $row->corners[0][0], 0.6);
        $this->assertEqualsWithDelta(1300, $row->corners[2][1], 0.6);
        [$w, $h] = getimagesizefromstring(Storage::disk('attachments')->get($row->path));
        // the cropped region is about 1440 x 975 (landscape-ish), not the full portrait page
        $this->assertLessThan(1.0, $h / $w);
    }

    public function test_pages_become_one_multi_page_pdf_and_unclear_pages_cannot_be_combined(): void
    {
        $this->fakeAttachments();
        $user = User::factory()->create();
        $svc = app(AttachmentService::class);
        $uid = (string) $user->employee_id;
        $good = Docs::jpeg($this->photo(self::UPRIGHT)['image'], 85);

        $p1 = $svc->scanPage($this->upload($good), $uid);
        $p2 = $svc->scanPage($this->upload($good), $uid);
        $this->assertTrue($p1->clarity_passed && $p2->clarity_passed);

        $pdf = $svc->finalizeScan([$p1->token, $p2->token], $uid);
        $this->assertSame('application/pdf', $pdf->mime);
        $this->assertSame(StagedAttachment::KIND_SCAN_PDF, $pdf->kind);
        $this->assertSame(2, $pdf->page_count);
        $bytes = Storage::disk('attachments')->get($pdf->path);
        $this->assertStringStartsWith('%PDF-', $bytes);
        $this->assertSame(2, PdfInspector::pageCount($pdf->absolutePath()), 'one PDF, two pages');
        $this->assertNotNull($p1->fresh()->attached_at, 'the pages are consumed');

        // A blurred photo is processed but flagged; it cannot be attached.
        $blurry = $svc->scanPage($this->upload(Docs::jpeg(Docs::blur($this->photo(self::UPRIGHT)['image'], 60), 85)), $uid);
        $this->assertFalse($blurry->clarity_passed);
        $this->assertSame('blurry', $blurry->clarity['code']);
        $this->expectException(AttachmentRejected::class);
        $svc->finalizeScan([$blurry->token], $uid);
    }

    public function test_a_scanned_pdf_keeps_its_page_images_for_the_viewer(): void
    {
        $this->fakeAttachments();
        $user = User::factory()->create();
        $svc = app(AttachmentService::class);
        $uid = (string) $user->employee_id;
        $good = Docs::jpeg($this->photo(self::UPRIGHT)['image'], 85);
        $pdf = $svc->finalizeScan([$svc->scanPage($this->upload($good), $uid)->token, $svc->scanPage($this->upload($good), $uid)->token], $uid);

        $type = LeaveSetting::create(['type' => 'Sick', 'days' => 5]);
        $leave = Leave::create(['user_id' => $user->id, 'leave_type' => $type->id, 'from_date' => now()->addDay(), 'to_date' => now()->addDay(), 'no_of_days' => 1, 'reason' => 'x', 'status' => 'pending']);
        $svc->attach($leave, $svc->resolve([$pdf->token], $uid), $uid);

        $state = app(AttachmentAccess::class)->present($leave->fresh(), $user, true);
        $this->assertSame('application/pdf', $state['files'][0]['mime_type']);
        $this->assertCount(2, $state['files'][0]['previews'], 'one signed image per page');
        $this->assertCount(1, $leave->fresh()->getMedia('attachments'), 'the previews are not documents');
    }

    public function test_unattached_scans_expire_after_24_hours_and_their_files_are_removed(): void
    {
        $this->fakeAttachments();
        $user = User::factory()->create();
        $svc = app(AttachmentService::class);
        $row = $svc->scanPage($this->upload(Docs::jpeg($this->photo(self::UPRIGHT)['image'], 85)), (string) $user->employee_id);
        $paths = [$row->path, $row->original_path];
        Storage::disk('attachments')->assertExists($paths);

        $this->travel(23)->hours();
        $this->assertSame(0, $svc->pruneExpired(), 'still inside 24 h');
        $this->assertTrue(StagedAttachment::usableBy((string) $user->employee_id)->where('token', $row->token)->exists());

        $this->travel(2)->hours();
        $this->assertFalse(StagedAttachment::usableBy((string) $user->employee_id)->where('token', $row->token)->exists(), 'expired scans can no longer be attached');
        $this->assertSame(1, $svc->pruneExpired());
        Storage::disk('attachments')->assertMissing($paths);
    }
}
