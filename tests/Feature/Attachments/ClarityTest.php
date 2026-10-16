<?php

namespace Tests\Feature\Attachments;

use App\Services\Attachments\AttachmentClarityService;
use Tests\Support\Attachments\FakePdfRasterizer;
use Tests\Support\Attachments\SyntheticDocuments as Docs;
use Tests\TestCase;

/** Every clarity rule on synthetic documents generated with GD (no real employee files). */
class ClarityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        ini_set('memory_limit', '1536M'); // synthetic pages are 2 million pixels each
    }

    private function svc(?FakePdfRasterizer $pdf = null): AttachmentClarityService
    {
        return new AttachmentClarityService($pdf ?? new FakePdfRasterizer);
    }

    public function test_sharp_pages_pass_including_a_compressed_jpeg_and_a_sparse_page(): void
    {
        $page = Docs::page();
        $this->assertTrue($this->svc()->checkImage($page)->passed);
        $this->assertTrue($this->svc()->checkImage(imagecreatefromstring(Docs::jpeg($page, 70)))->passed);
        $this->assertTrue($this->svc()->checkImage(Docs::page(1240, 1754, 4))->passed, 'a sparse sharp page is not mistaken for a blurred one');
    }

    public function test_moderate_blur_still_readable_and_strong_blur_fails_with_the_blurry_guidance(): void
    {
        $page = Docs::page();
        foreach ([4, 8, 16] as $passes) {
            $this->assertTrue($this->svc()->checkImage(Docs::blur($page, $passes))->passed, "blur {$passes} passes is readable");
        }
        foreach ([24, 32, 64] as $passes) {
            $r = $this->svc()->checkImage(Docs::blur($page, $passes));
            $this->assertFalse($r->passed, "blur {$passes} passes is unreadable");
            $this->assertSame('blurry', $r->code);
            $this->assertStringContainsString('too blurry to read', $r->message);
            $this->assertStringContainsString('Hold the phone steady', $r->message);
        }
    }

    public function test_dark_overexposed_small_and_blank_each_get_their_own_message(): void
    {
        $page = Docs::page();
        $this->assertSame('dark', $this->svc()->checkImage(Docs::dark($page))->code);
        $this->assertSame('bright', $this->svc()->checkImage(Docs::overexposed($page))->code);
        $small = $this->svc()->checkImage(Docs::lowRes($page));
        $this->assertSame('too_small', $small->code);
        $this->assertStringContainsString('too small', $small->message);
        $this->assertSame('blank', $this->svc()->checkImage(Docs::blank())->code);
        $this->assertStringContainsString('looks blank', $this->svc()->checkImage(Docs::blank())->message);
    }

    public function test_a_moderately_dim_but_clear_photo_passes(): void
    {
        $this->assertTrue($this->svc()->checkImage(Docs::dark(Docs::page(), 150))->passed);
    }

    public function test_min_resolution_is_1200_by_800(): void
    {
        $page = Docs::page(1240, 1754);
        $this->assertSame('too_small', $this->svc()->checkImage(Docs::lowRes($page, 1100))->code); // long edge 1100 < 1200
        // 1500 x 1060 meets both edges (the content is judged by the other rules)
        $this->assertNotSame('too_small', $this->svc()->checkImage(Docs::lowRes($page, 1500))->code);
    }

    public function test_a_text_pdf_passes_without_rasterising(): void
    {
        $pdf = new FakePdfRasterizer(pageCount: 2, text: true, pages: [Docs::blank()]);
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4');
        $this->assertTrue($this->svc($pdf)->checkPdf($path)->passed, 'text PDFs pass on page and size limits only');
    }

    public function test_a_scanned_pdf_goes_through_the_rasteriser_and_each_page_is_checked(): void
    {
        $path = tempnam(sys_get_temp_dir(), 'pdf');
        file_put_contents($path, '%PDF-1.4');

        $good = new FakePdfRasterizer(pageCount: 2, text: false, pages: [Docs::page(), Docs::page()]);
        $this->assertTrue($this->svc($good)->checkPdf($path)->passed);

        $bad = new FakePdfRasterizer(pageCount: 2, text: false, pages: [Docs::page(), Docs::blur(Docs::page(), 40)]);
        $r = $this->svc($bad)->checkPdf($path);
        $this->assertFalse($r->passed);
        $this->assertSame('blurry', $r->code);
        $this->assertSame(2, $r->metrics['page'], 'the failing page is named');
    }
}
