<?php

namespace App\Services\Attachments;

use App\Services\Attachments\Contracts\PdfRasterizer;
use RuntimeException;

/** Production rasteriser: Imagick over Ghostscript. Page count and text detection do not need either. */
class ImagickPdfRasterizer implements PdfRasterizer
{
    public function pageCount(string $path): int
    {
        return PdfInspector::pageCount($path);
    }

    public function hasText(string $path): bool
    {
        return PdfInspector::hasText($path);
    }

    public function rasterize(string $path, int $dpi, int $maxPages): array
    {
        if (! class_exists(\Imagick::class)) {
            throw new RuntimeException('PDF rasterising needs the Imagick extension.');
        }

        $out = [];
        $pages = min($maxPages, $this->pageCount($path));
        for ($i = 0; $i < $pages; $i++) {
            $im = new \Imagick;
            $im->setResolution($dpi, $dpi);
            $im->readImage($path.'['.$i.']');
            $im->setImageBackgroundColor('white');
            $im = $im->mergeImageLayers(\Imagick::LAYERMETHOD_FLATTEN);
            $im->setImageFormat('png');
            $file = tempnam(sys_get_temp_dir(), 'pdfpg_').'.png';
            $im->writeImage($file);
            $im->clear();
            $out[] = $file;
        }

        return $out;
    }
}
