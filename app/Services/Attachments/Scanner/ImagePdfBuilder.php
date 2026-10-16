<?php

namespace App\Services\Attachments\Scanner;

/** Builds one PDF from JPEG pages (each page embedded as-is, sized for its pixel dimensions at the given dpi). Pure PHP. */
final class ImagePdfBuilder
{
    /** @param list<string> $jpegPaths */
    public static function build(array $jpegPaths, int $dpi = 150): string
    {
        $objects = [];
        $pageIds = [];
        // Object ids: 1 catalog, 2 pages, then 3 objects per page (page, content, image).
        $next = 3;
        foreach ($jpegPaths as $path) {
            $data = (string) file_get_contents($path);
            [$w, $h] = getimagesizefromstring($data);
            $ptW = round($w * 72 / $dpi, 2);
            $ptH = round($h * 72 / $dpi, 2);
            $pageId = $next++;
            $contentId = $next++;
            $imageId = $next++;
            $pageIds[] = $pageId;
            $content = "q {$ptW} 0 0 {$ptH} 0 0 cm /Im0 Do Q";
            $objects[$pageId] = "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 {$ptW} {$ptH}] /Resources << /XObject << /Im0 {$imageId} 0 R >> >> /Contents {$contentId} 0 R >>";
            $objects[$contentId] = '<< /Length '.strlen($content)." >>\nstream\n{$content}\nendstream";
            $objects[$imageId] = "<< /Type /XObject /Subtype /Image /Width {$w} /Height {$h} /ColorSpace ".(self::isGrey($data) ? '/DeviceGray' : '/DeviceRGB').' /BitsPerComponent 8 /Filter /DCTDecode /Length '.strlen($data)." >>\nstream\n{$data}\nendstream";
        }
        $objects[1] = '<< /Type /Catalog /Pages 2 0 R >>';
        $objects[2] = '<< /Type /Pages /Kids ['.implode(' ', array_map(fn ($id) => "{$id} 0 R", $pageIds)).'] /Count '.count($pageIds).' >>';
        ksort($objects);

        $pdf = "%PDF-1.4\n";
        $offsets = [];
        foreach ($objects as $id => $body) {
            $offsets[$id] = strlen($pdf);
            $pdf .= "{$id} 0 obj\n{$body}\nendobj\n";
        }
        $xref = strlen($pdf);
        $pdf .= "xref\n0 ".(count($objects) + 1)."\n0000000000 65535 f \n";
        foreach ($offsets as $off) {
            $pdf .= sprintf("%010d 00000 n \n", $off);
        }
        $pdf .= 'trailer << /Size '.(count($objects) + 1)." /Root 1 0 R >>\nstartxref\n{$xref}\n%%EOF\n";

        return $pdf;
    }

    private static function isGrey(string $jpeg): bool
    {
        $info = getimagesizefromstring($jpeg);

        return ($info['channels'] ?? 3) === 1;
    }
}
