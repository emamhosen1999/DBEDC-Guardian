<?php

namespace App\Services\Attachments\Contracts;

/** Reads PDFs: page count, extractable text, and rasterising pages for the clarity check (Imagick + Ghostscript in production; a fake in tests). */
interface PdfRasterizer
{
    public function pageCount(string $path): int;

    /** True when the PDF carries extractable text (a text PDF) rather than being a scan of page images. */
    public function hasText(string $path): bool;

    /**
     * Render the first pages to PNG files at the given resolution.
     *
     * @return list<string> absolute paths of temporary PNG files (the caller removes them)
     */
    public function rasterize(string $path, int $dpi, int $maxPages): array;
}
