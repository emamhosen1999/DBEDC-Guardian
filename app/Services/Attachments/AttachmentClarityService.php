<?php

namespace App\Services\Attachments;

use App\Services\Attachments\Contracts\PdfRasterizer;
use GdImage;

/**
 * Decides whether a document image/PDF is readable. Server-side and authoritative; the web pre-check only mirrors it.
 *
 * Images: resolution, sharpness (variance of the Laplacian over the busiest text tiles of a ~1000 px grey copy),
 * exposure (mean luminance), contrast (paper vs ink level) and a not-blank test (ink share). PDFs: text PDFs pass on
 * page and size limits alone; scanned PDFs are rasterised at ~150 dpi and their first pages are checked like images.
 * Thresholds live in config/attachments.php.
 */
class AttachmentClarityService
{
    public function __construct(private readonly PdfRasterizer $pdf) {}

    public function checkImageFile(string $path): ClarityResult
    {
        $image = @imagecreatefromstring((string) file_get_contents($path));
        if (! $image) {
            return ClarityResult::fail('unreadable');
        }

        return $this->checkImage($image);
    }

    public function checkImage(GdImage $image): ClarityResult
    {
        $cfg = config('attachments.clarity');
        $w = imagesx($image);
        $h = imagesy($image);
        $long = max($w, $h);
        $short = min($w, $h);
        $metrics = ['width' => $w, 'height' => $h];

        if ($long < $cfg['min_long_edge'] || $short < $cfg['min_short_edge']) {
            return ClarityResult::fail('too_small', $metrics);
        }

        $grey = $this->greyScaled($image, (int) $cfg['sharpness_long_edge']);
        $stats = $this->luminanceStats($grey['pixels'], $grey['w'], $grey['h']);
        $metrics += [
            'mean_luminance' => round($stats['mean'], 1),
            'std_luminance' => round($stats['std'], 1),
            'ink_contrast' => round($stats['ink_contrast'], 1),
            'ink_fraction' => round($stats['ink_fraction'], 4),
        ];

        if ($stats['mean'] < $cfg['min_mean_luminance']) {
            return ClarityResult::fail('dark', $metrics);
        }
        if ($stats['ink_fraction'] < $cfg['min_ink_fraction']) {
            return ClarityResult::fail('blank', $metrics);
        }
        if ($stats['ink_contrast'] < $cfg['min_ink_contrast']) {
            return ClarityResult::fail($stats['mean'] > $cfg['max_mean_luminance'] - 25 ? 'bright' : 'low_contrast', $metrics);
        }

        $metrics['sharpness'] = round($this->sharpness($grey['pixels'], $grey['w'], $grey['h'], (int) $cfg['sharpness_tile'], (float) $cfg['sharpness_top_tile_fraction']), 1);
        if ($metrics['sharpness'] < $cfg['min_sharpness']) {
            return ClarityResult::fail('blurry', $metrics);
        }

        return ClarityResult::pass($metrics);
    }

    /** @param int $pages page count already established by the caller (avoids a second parse) */
    public function checkPdf(string $path): ClarityResult
    {
        if ($this->pdf->hasText($path)) {
            return ClarityResult::pass(['pdf' => 'text']);
        }

        $files = $this->pdf->rasterize($path, (int) config('attachments.pdf.raster_dpi'), (int) config('attachments.pdf.check_pages'));
        try {
            if ($files === []) {
                return ClarityResult::fail('unreadable');
            }
            foreach ($files as $i => $file) {
                // A page rasterised at 150 dpi is A4-sized by construction; the resolution floor is therefore not applied again.
                $result = $this->checkImageFile($file);
                if (! $result->passed) {
                    return new ClarityResult(false, $result->code, $result->message, $result->metrics + ['pdf' => 'scan', 'page' => $i + 1]);
                }
            }
        } finally {
            foreach ($files as $file) {
                @unlink($file);
            }
        }

        return ClarityResult::pass(['pdf' => 'scan']);
    }

    /** @return array{pixels: \SplFixedArray<int>, w: int, h: int} grey image scaled to the given long edge (never enlarged) */
    private function greyScaled(GdImage $image, int $longEdge): array
    {
        $w = imagesx($image);
        $h = imagesy($image);
        $scale = min(1.0, $longEdge / max($w, $h));
        $tw = max(8, (int) round($w * $scale));
        $th = max(8, (int) round($h * $scale));
        $small = imagecreatetruecolor($tw, $th);
        imagecopyresampled($small, $image, 0, 0, 0, 0, $tw, $th, $w, $h);
        imagefilter($small, IMG_FILTER_GRAYSCALE);

        $pixels = new \SplFixedArray($tw * $th);
        $i = 0;
        for ($y = 0; $y < $th; $y++) {
            for ($x = 0; $x < $tw; $x++) {
                $pixels[$i++] = imagecolorat($small, $x, $y) & 0xFF;
            }
        }

        return ['pixels' => $pixels, 'w' => $tw, 'h' => $th];
    }

    /** @return array{mean: float, std: float, ink_contrast: float, ink_fraction: float} */
    private function luminanceStats(\SplFixedArray $px, int $w, int $h): array
    {
        $n = $w * $h;
        $hist = array_fill(0, 256, 0);
        $sum = 0;
        $sumSq = 0;
        for ($i = 0; $i < $n; $i++) {
            $v = $px[$i];
            $hist[$v]++;
            $sum += $v;
            $sumSq += $v * $v;
        }
        $mean = $sum / $n;
        $std = sqrt(max(0.0, $sumSq / $n - $mean * $mean));

        $percentile = function (float $p) use ($hist, $n): int {
            $target = $p * $n;
            $acc = 0;
            for ($v = 0; $v < 256; $v++) {
                $acc += $hist[$v];
                if ($acc >= $target) {
                    return $v;
                }
            }

            return 255;
        };
        $paper = $percentile(0.5);
        $inkLevel = $percentile(0.01);

        // Ink: clearly darker than the paper level.
        $inkCut = max(0, $paper - 45);
        $ink = 0;
        for ($v = 0; $v < $inkCut; $v++) {
            $ink += $hist[$v];
        }

        return ['mean' => $mean, 'std' => $std, 'ink_contrast' => (float) ($paper - $inkLevel), 'ink_fraction' => $ink / $n];
    }

    /**
     * Variance of the Laplacian per tile; the mean of the busiest tiles (those that actually contain text/ink) is the score.
     * A sparse but sharp page keeps a high score, a blurred one does not.
     */
    private function sharpness(\SplFixedArray $px, int $w, int $h, int $tile, float $topFraction): float
    {
        $tilesX = max(1, intdiv($w, $tile));
        $tilesY = max(1, intdiv($h, $tile));
        $sum = array_fill(0, $tilesX * $tilesY, 0.0);
        $sumSq = array_fill(0, $tilesX * $tilesY, 0.0);
        $cnt = array_fill(0, $tilesX * $tilesY, 0);

        for ($y = 1; $y < $h - 1; $y++) {
            $ty = min($tilesY - 1, intdiv($y, $tile));
            $row = $y * $w;
            for ($x = 1; $x < $w - 1; $x++) {
                $i = $row + $x;
                $lap = 4 * $px[$i] - $px[$i - 1] - $px[$i + 1] - $px[$i - $w] - $px[$i + $w];
                $t = $ty * $tilesX + min($tilesX - 1, intdiv($x, $tile));
                $sum[$t] += $lap;
                $sumSq[$t] += $lap * $lap;
                $cnt[$t]++;
            }
        }

        $vars = [];
        foreach ($cnt as $t => $c) {
            if ($c > 0) {
                $mean = $sum[$t] / $c;
                $vars[] = $sumSq[$t] / $c - $mean * $mean;
            }
        }
        rsort($vars);
        $take = max(1, (int) ceil(count($vars) * $topFraction));

        return array_sum(array_slice($vars, 0, $take)) / $take;
    }
}
