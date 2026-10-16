<?php

namespace Tests\Support\Attachments;

use App\Services\Attachments\Contracts\PdfRasterizer;
use GdImage;

/** Test double for the Imagick + Ghostscript rasteriser: page count and text come from the test, pages are synthetic images. */
final class FakePdfRasterizer implements PdfRasterizer
{
    /** @param list<GdImage> $pages images returned as the rasterised pages */
    public function __construct(public int $pageCount = 1, public bool $text = false, public array $pages = []) {}

    public function pageCount(string $path): int
    {
        return $this->pageCount;
    }

    public function hasText(string $path): bool
    {
        return $this->text;
    }

    public function rasterize(string $path, int $dpi, int $maxPages): array
    {
        $files = [];
        foreach (array_slice($this->pages, 0, $maxPages) as $img) {
            $file = tempnam(sys_get_temp_dir(), 'fakepg_').'.png';
            imagepng($img, $file);
            $files[] = $file;
        }

        return $files;
    }
}
