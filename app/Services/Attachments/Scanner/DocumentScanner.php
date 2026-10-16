<?php

namespace App\Services\Attachments\Scanner;

use GdImage;
use RuntimeException;

/**
 * Server-side document scanner on GD (present in every environment, so the same code runs in production and in tests):
 * finds the page's four corners, flattens it to a rectangle (perspective correction, which also deskews and crops), then
 * cleans it (illumination levelling, white balance, auto-level, gentle sharpening) in colour, grey or black-and-white.
 *
 * Corner detection: downscale, grey, blur, Otsu split, connected components of the bright (or dark) class, hole fill,
 * convex hull, then the largest inscribed quadrilateral of the hull; the confidence reflects how page-like that blob is.
 */
class DocumentScanner
{
    /** @return array{corners: list<array{0: float, 1: float}>, confidence: float} corners are TL, TR, BR, BL in source pixels */
    public function detect(GdImage $src): array
    {
        $w = imagesx($src);
        $h = imagesy($src);
        $scale = min(1.0, (int) config('attachments.scanner.detect_long_edge') / max($w, $h));
        $dw = max(16, (int) round($w * $scale));
        $dh = max(16, (int) round($h * $scale));

        $small = imagecreatetruecolor($dw, $dh);
        imagecopyresampled($small, $src, 0, 0, 0, 0, $dw, $dh, $w, $h);
        imagefilter($small, IMG_FILTER_GRAYSCALE);
        for ($i = 0; $i < 3; $i++) {
            imagefilter($small, IMG_FILTER_GAUSSIAN_BLUR);
        }

        $grey = new \SplFixedArray($dw * $dh);
        $hist = array_fill(0, 256, 0);
        for ($y = 0, $i = 0; $y < $dh; $y++) {
            for ($x = 0; $x < $dw; $x++, $i++) {
                $v = imagecolorat($small, $x, $y) & 0xFF;
                $grey[$i] = $v;
                $hist[$v]++;
            }
        }
        $t = $this->otsu($hist, $dw * $dh);

        $best = null;
        foreach ([true, false] as $bright) {
            $mask = new \SplFixedArray($dw * $dh);
            for ($i = 0, $n = $dw * $dh; $i < $n; $i++) {
                $mask[$i] = ($bright ? $grey[$i] > $t : $grey[$i] <= $t) ? 1 : 0;
            }
            $blob = $this->largestComponent($mask, $dw, $dh);
            if ($blob === null) {
                continue;
            }
            $candidate = $this->scoreBlob($blob, $mask, $dw, $dh);
            if ($candidate !== null && ($best === null || $candidate['confidence'] > $best['confidence'])) {
                $best = $candidate;
            }
        }

        if ($best === null) {
            $m = 0.03;

            return [
                'corners' => [[$w * $m, $h * $m], [$w * (1 - $m), $h * $m], [$w * (1 - $m), $h * (1 - $m)], [$w * $m, $h * (1 - $m)]],
                'confidence' => 0.1,
            ];
        }

        $inv = 1 / $scale;

        return [
            'corners' => array_map(fn ($p) => [round($p[0] * $inv, 1), round($p[1] * $inv, 1)], $best['corners']),
            'confidence' => round($best['confidence'], 3),
        ];
    }

    /**
     * Flatten the quad to a page and clean it.
     *
     * @param  list<array{0: float, 1: float}>  $corners  TL, TR, BR, BL in source pixels
     */
    public function flatten(GdImage $src, array $corners, string $mode = 'color'): GdImage
    {
        $corners = $this->orderCorners($corners);
        [$tl, $tr, $br, $bl] = $corners;
        $width = ($this->dist($tl, $tr) + $this->dist($bl, $br)) / 2;
        $height = ($this->dist($tl, $bl) + $this->dist($tr, $br)) / 2;
        if ($width < 20 || $height < 20) {
            throw new RuntimeException('The detected page is too small.');
        }

        $cap = (int) config('attachments.scanner.output_max_long_edge');
        $k = min(1.0, $cap / max($width, $height));
        $outW = max(16, (int) round($width * $k));
        $outH = max(16, (int) round($height * $k));

        // Resample the source so the page is about 1:1 with the output, then map with nearest-pixel lookups.
        $s = $k;
        $work = $src;
        if ($s < 0.999) {
            $work = imagescale($src, max(1, (int) round(imagesx($src) * $s)), max(1, (int) round(imagesy($src) * $s)), IMG_BILINEAR_FIXED);
            $corners = array_map(fn ($p) => [$p[0] * $s, $p[1] * $s], $corners);
        }
        $sw = imagesx($work);
        $sh = imagesy($work);

        $H = Homography::rectToQuad($outW, $outH, $corners)->coefficients();
        [$a, $b, $c, $d, $e, $f, $g, $hh] = $H;

        $out = imagecreatetruecolor($outW, $outH);
        imagefill($out, 0, 0, 0xFFFFFF);
        for ($y = 0; $y < $outH; $y++) {
            $py = $y + 0.5;
            for ($x = 0; $x < $outW; $x++) {
                $px = $x + 0.5;
                $den = $g * $px + $hh * $py + 1.0;
                $u = (int) (($a * $px + $b * $py + $c) / $den);
                $v = (int) (($d * $px + $e * $py + $f) / $den);
                if ($u < 0) {
                    $u = 0;
                } elseif ($u >= $sw) {
                    $u = $sw - 1;
                }
                if ($v < 0) {
                    $v = 0;
                } elseif ($v >= $sh) {
                    $v = $sh - 1;
                }
                imagesetpixel($out, $x, $y, imagecolorat($work, $u, $v));
            }
        }
        if ($work !== $src) {
            unset($work);
        }

        return $this->enhance($out, $mode);
    }

    /**
     * Illumination levelling + white balance + auto-level (+ threshold for black-and-white) + gentle sharpening.
     */
    public function enhance(GdImage $img, string $mode): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);

        // Background (paper + light) estimate from a tiny copy blurred hard: text is too thin to pull it down much.
        $bw = max(4, intdiv($w, 24));
        $bh = max(4, intdiv($h, 24));
        $tiny = imagecreatetruecolor($bw, $bh);
        imagecopyresampled($tiny, $img, 0, 0, 0, 0, $bw, $bh, $w, $h);
        $bg = [];
        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                $rgb = imagecolorat($tiny, $x, $y);
                $bg[$y][$x] = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
            }
        }
        // The paper is the brightest common level in a neighbourhood: replace each cell by the max of its 3x3 window, then smooth.
        $paper = [];
        for ($y = 0; $y < $bh; $y++) {
            for ($x = 0; $x < $bw; $x++) {
                $m = [1, 1, 1];
                for ($dy = -1; $dy <= 1; $dy++) {
                    for ($dx = -1; $dx <= 1; $dx++) {
                        $yy = min($bh - 1, max(0, $y + $dy));
                        $xx = min($bw - 1, max(0, $x + $dx));
                        for ($ch = 0; $ch < 3; $ch++) {
                            $m[$ch] = max($m[$ch], $bg[$yy][$xx][$ch]);
                        }
                    }
                }
                $paper[$y][$x] = $m;
            }
        }

        $out = imagecreatetruecolor($w, $h);
        $bx = ($bw - 1) / max(1, $w - 1);
        $by = ($bh - 1) / max(1, $h - 1);
        $grey = $mode !== 'color';
        $bwMode = $mode === 'bw';
        for ($y = 0; $y < $h; $y++) {
            $fy = $y * $by;
            $y0 = (int) $fy;
            $y1 = min($bh - 1, $y0 + 1);
            $wy = $fy - $y0;
            for ($x = 0; $x < $w; $x++) {
                $fx = $x * $bx;
                $x0 = (int) $fx;
                $x1 = min($bw - 1, $x0 + 1);
                $wx = $fx - $x0;
                $rgb = imagecolorat($img, $x, $y);
                $pix = [($rgb >> 16) & 0xFF, ($rgb >> 8) & 0xFF, $rgb & 0xFF];
                $res = [0, 0, 0];
                for ($ch = 0; $ch < 3; $ch++) {
                    $top = $paper[$y0][$x0][$ch] * (1 - $wx) + $paper[$y0][$x1][$ch] * $wx;
                    $bot = $paper[$y1][$x0][$ch] * (1 - $wx) + $paper[$y1][$x1][$ch] * $wx;
                    $p = $top * (1 - $wy) + $bot * $wy;
                    // Paper -> white; slight gain pushes the paper tone clear of grey, ink stays dark.
                    $res[$ch] = $pix[$ch] / max(24.0, $p) * 250.0;
                }
                if ($grey) {
                    $l = 0.299 * $res[0] + 0.587 * $res[1] + 0.114 * $res[2];
                    if ($bwMode) {
                        $l = $l < 205 ? 0 : 255;
                    } else {
                        // Auto-level: pull the grey range apart (ink darker, paper white).
                        $l = ($l - 40) * 1.19;
                    }
                    $res = [$l, $l, $l];
                } else {
                    for ($ch = 0; $ch < 3; $ch++) {
                        $res[$ch] = ($res[$ch] - 20) * 1.1;
                    }
                }
                imagesetpixel($out, $x, $y, (self::clamp($res[0]) << 16) | (self::clamp($res[1]) << 8) | self::clamp($res[2]));
            }
        }

        if (! $bwMode) {
            imageconvolution($out, [[0, -0.5, 0], [-0.5, 3.0, -0.5], [0, -0.5, 0]], 1, 0);
        }

        return $out;
    }

    /** @param list<array{0: float, 1: float}> $corners @return list<array{0: float, 1: float}> TL, TR, BR, BL */
    public function orderCorners(array $corners): array
    {
        $sorted = $corners;
        usort($sorted, fn ($p, $q) => $p[1] <=> $q[1]);
        $top = array_slice($sorted, 0, 2);
        $bottom = array_slice($sorted, 2, 2);
        usort($top, fn ($p, $q) => $p[0] <=> $q[0]);
        usort($bottom, fn ($p, $q) => $p[0] <=> $q[0]);

        return [$top[0], $top[1], $bottom[1], $bottom[0]];
    }

    private static function clamp(float $v): int
    {
        return $v < 0 ? 0 : ($v > 255 ? 255 : (int) $v);
    }

    private function dist(array $p, array $q): float
    {
        return hypot($p[0] - $q[0], $p[1] - $q[1]);
    }

    private function otsu(array $hist, int $total): int
    {
        $sum = 0;
        for ($i = 0; $i < 256; $i++) {
            $sum += $i * $hist[$i];
        }
        $sumB = 0;
        $wB = 0;
        $best = 0.0;
        $threshold = 127;
        for ($i = 0; $i < 256; $i++) {
            $wB += $hist[$i];
            if ($wB === 0) {
                continue;
            }
            $wF = $total - $wB;
            if ($wF === 0) {
                break;
            }
            $sumB += $i * $hist[$i];
            $mB = $sumB / $wB;
            $mF = ($sum - $sumB) / $wF;
            $between = $wB * $wF * ($mB - $mF) ** 2;
            if ($between > $best) {
                $best = $between;
                $threshold = $i;
            }
        }

        return $threshold;
    }

    /** @return list<int>|null pixel indexes of the largest 4-connected blob of 1s */
    private function largestComponent(\SplFixedArray $mask, int $w, int $h): ?array
    {
        $seen = new \SplFixedArray($w * $h);
        $best = null;
        $bestSize = 0;
        for ($start = 0, $n = $w * $h; $start < $n; $start++) {
            if ($mask[$start] !== 1 || $seen[$start]) {
                continue;
            }
            $stack = [$start];
            $seen[$start] = 1;
            $blob = [];
            while ($stack) {
                $p = array_pop($stack);
                $blob[] = $p;
                $x = $p % $w;
                $y = intdiv($p, $w);
                foreach ([[$x > 0, $p - 1], [$x < $w - 1, $p + 1], [$y > 0, $p - $w], [$y < $h - 1, $p + $w]] as [$ok, $q]) {
                    if ($ok && $mask[$q] === 1 && ! $seen[$q]) {
                        $seen[$q] = 1;
                        $stack[] = $q;
                    }
                }
            }
            if (count($blob) > $bestSize) {
                $bestSize = count($blob);
                $best = $blob;
            }
        }

        return $best;
    }

    /** @return array{corners: list<array{0: float, 1: float}>, confidence: float}|null */
    private function scoreBlob(array $blob, \SplFixedArray $mask, int $w, int $h): ?array
    {
        $area = count($blob);
        if ($area < 0.08 * $w * $h) {
            return null;
        }

        // Hull of the blob: only boundary pixels matter, and per row/column extremes are enough for the hull.
        $rowMin = [];
        $rowMax = [];
        foreach ($blob as $p) {
            $x = $p % $w;
            $y = intdiv($p, $w);
            if (! isset($rowMin[$y]) || $x < $rowMin[$y]) {
                $rowMin[$y] = $x;
            }
            if (! isset($rowMax[$y]) || $x > $rowMax[$y]) {
                $rowMax[$y] = $x;
            }
        }
        $pts = [];
        foreach ($rowMin as $y => $x) {
            $pts[] = [(float) $x, (float) $y];
            $pts[] = [(float) $rowMax[$y], (float) $y];
        }
        $hull = $this->convexHull($pts);
        if (count($hull) < 4) {
            return null;
        }
        $hull = $this->decimate($hull, 28);
        $quad = $this->maxAreaQuad($hull);
        $quadArea = $this->polyArea($quad);
        if ($quadArea <= 0) {
            return null;
        }

        // Holes (text, shadows) do not reduce a page's solidity: use the hull-filled area against the quad.
        $hullArea = $this->polyArea($hull);
        $solidity = min(1.0, $hullArea / max(1.0, $hullArea)) * min(1.0, $quadArea / $hullArea);
        $frameFrac = $quadArea / ($w * $h);
        // The blob must really fill its hull (a page is solid once its text is ignored), not be a ragged region.
        $fill = min(1.0, $area / $hullArea);
        $fillScore = max(0.0, min(1.0, ($fill - 0.55) / 0.3));
        $sizeScore = $frameFrac >= 0.2 ? 1.0 : $frameFrac / 0.2;
        $quadScore = max(0.0, min(1.0, ($solidity - 0.85) / 0.1));

        return ['corners' => $quad, 'confidence' => $fillScore * $sizeScore * (0.5 + 0.5 * $quadScore)];
    }

    /** @param list<array{0: float, 1: float}> $pts */
    private function convexHull(array $pts): array
    {
        usort($pts, fn ($p, $q) => $p[0] <=> $q[0] ?: $p[1] <=> $q[1]);
        $pts = array_values(array_unique($pts, SORT_REGULAR));
        $cross = fn ($o, $a, $b) => ($a[0] - $o[0]) * ($b[1] - $o[1]) - ($a[1] - $o[1]) * ($b[0] - $o[0]);
        $lower = [];
        foreach ($pts as $p) {
            while (count($lower) >= 2 && $cross($lower[count($lower) - 2], $lower[count($lower) - 1], $p) <= 0) {
                array_pop($lower);
            }
            $lower[] = $p;
        }
        $upper = [];
        foreach (array_reverse($pts) as $p) {
            while (count($upper) >= 2 && $cross($upper[count($upper) - 2], $upper[count($upper) - 1], $p) <= 0) {
                array_pop($upper);
            }
            $upper[] = $p;
        }
        array_pop($lower);
        array_pop($upper);

        return array_merge($lower, $upper);
    }

    /** Keep at most $max hull points, dropping the ones that contribute least area. */
    private function decimate(array $hull, int $max): array
    {
        while (count($hull) > $max) {
            $n = count($hull);
            $minArea = INF;
            $idx = 0;
            for ($i = 0; $i < $n; $i++) {
                $a = $hull[($i + $n - 1) % $n];
                $b = $hull[$i];
                $c = $hull[($i + 1) % $n];
                $tri = abs(($b[0] - $a[0]) * ($c[1] - $a[1]) - ($c[0] - $a[0]) * ($b[1] - $a[1])) / 2;
                if ($tri < $minArea) {
                    $minArea = $tri;
                    $idx = $i;
                }
            }
            array_splice($hull, $idx, 1);
        }

        return $hull;
    }

    private function maxAreaQuad(array $hull): array
    {
        $n = count($hull);
        $best = [];
        $bestArea = -1.0;
        for ($i = 0; $i < $n - 3; $i++) {
            for ($j = $i + 1; $j < $n - 2; $j++) {
                for ($k = $j + 1; $k < $n - 1; $k++) {
                    for ($l = $k + 1; $l < $n; $l++) {
                        $q = [$hull[$i], $hull[$j], $hull[$k], $hull[$l]];
                        $area = $this->polyArea($q);
                        if ($area > $bestArea) {
                            $bestArea = $area;
                            $best = $q;
                        }
                    }
                }
            }
        }

        return $this->orderCorners($best);
    }

    private function polyArea(array $poly): float
    {
        $a = 0.0;
        $n = count($poly);
        for ($i = 0; $i < $n; $i++) {
            $p = $poly[$i];
            $q = $poly[($i + 1) % $n];
            $a += $p[0] * $q[1] - $q[0] * $p[1];
        }

        return abs($a) / 2;
    }
}
