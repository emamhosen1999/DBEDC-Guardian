<?php

namespace Tests\Support\Attachments;

use App\Services\Attachments\Scanner\Homography;
use GdImage;

/** Synthetic documents for the clarity and scanner tests and for calibrating the thresholds. No real employee files. */
final class SyntheticDocuments
{
    /** A printed A4-ish page at about 150 dpi: dark text lines on white paper. */
    public static function page(int $w = 1240, int $h = 1754, int $lines = 54): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 248, 248, 246));
        $words = ['Leave', 'application', 'Medical', 'certificate', 'issued', 'for', 'rest', 'Employee', 'signature', 'Approved', 'duration', 'days', 'hospital', 'doctor', 'reference'];
        mt_srand(7);
        // Draw the text small with the built-in bitmap font, then enlarge so the strokes are a realistic ~3 px wide.
        $sw = intdiv($w, 2);
        $sh = intdiv($h, 2);
        $small = imagecreatetruecolor($sw, $sh);
        imagefill($small, 0, 0, imagecolorallocate($small, 248, 248, 246));
        $ink = imagecolorallocate($small, 20, 20, 24);
        $y = 20;
        for ($i = 0; $i < $lines && $y < $sh - 20; $i++) {
            $text = '';
            while (strlen($text) < 70) {
                $text .= $words[mt_rand(0, count($words) - 1)].' ';
            }
            imagestring($small, 3, 14, $y, trim($text), $ink);
            $y += 15;
        }
        imagecopyresampled($img, $small, 0, 0, 0, 0, $w, $h, $sw, $sh);

        return $img;
    }

    public static function blur(GdImage $img, int $passes): GdImage
    {
        $copy = self::copy($img);
        for ($i = 0; $i < $passes; $i++) {
            imagefilter($copy, IMG_FILTER_GAUSSIAN_BLUR);
        }

        return $copy;
    }

    public static function dark(GdImage $img, int $amount = 215): GdImage
    {
        $copy = self::copy($img);
        imagefilter($copy, IMG_FILTER_BRIGHTNESS, -$amount);

        return $copy;
    }

    /** Over-exposed: highlights blown and the ink lifted towards the paper level. */
    public static function overexposed(GdImage $img): GdImage
    {
        $copy = self::copy($img);
        $w = imagesx($copy);
        $h = imagesy($copy);
        for ($y = 0; $y < $h; $y++) {
            for ($x = 0; $x < $w; $x++) {
                $c = imagecolorat($copy, $x, $y) & 0xFF;
                $v = (int) (255 - (255 - $c) * 0.25);
                imagesetpixel($copy, $x, $y, ($v << 16) | ($v << 8) | $v);
            }
        }

        return $copy;
    }

    public static function lowRes(GdImage $img, int $longEdge = 700): GdImage
    {
        $w = imagesx($img);
        $h = imagesy($img);
        $k = $longEdge / max($w, $h);

        return imagescale($img, (int) round($w * $k), (int) round($h * $k), IMG_BILINEAR_FIXED);
    }

    public static function blank(int $w = 1240, int $h = 1754): GdImage
    {
        $img = imagecreatetruecolor($w, $h);
        imagefill($img, 0, 0, imagecolorallocate($img, 245, 245, 243));

        return $img;
    }

    /**
     * A photo of the page lying on a textured desk. Corners are given as fractions of the canvas (TL, TR, BR, BL).
     *
     * @param  list<array{0: float, 1: float}>|null  $quad  page corners in canvas pixels; default is a slightly rotated, tilted page
     * @return array{image: GdImage, quad: list<array{0: float, 1: float}>}
     */
    public static function photo(GdImage $page, int $cw = 1800, int $ch = 1500, ?array $quad = null, float $light = 1.0, int $seed = 3): array
    {
        $quad ??= [[330, 120], [1380, 190], [1330, 1390], [250, 1330]];
        $pw = imagesx($page);
        $ph = imagesy($page);

        mt_srand($seed);
        $canvas = imagecreatetruecolor($cw, $ch);
        for ($y = 0; $y < $ch; $y++) {
            // Wood-grain-like banded texture, mid-dark so the paper is the brightest large region.
            $band = 80 + (int) (22 * sin($y / 9.0) + 10 * sin($y / 3.1));
            for ($x = 0; $x < $cw; $x += 1) {
                $n = $band + mt_rand(-14, 14) + (int) (8 * sin($x / 40.0));
                imagesetpixel($canvas, $x, $y, ($n << 16) | ((int) ($n * 0.78) << 8) | (int) ($n * 0.55));
            }
        }

        $inv = Homography::quadToRect($quad, $pw, $ph);
        $xs = array_column($quad, 0);
        $ys = array_column($quad, 1);
        for ($y = (int) min($ys); $y <= (int) max($ys); $y++) {
            for ($x = (int) min($xs); $x <= (int) max($xs); $x++) {
                [$u, $v] = $inv->map($x + 0.5, $y + 0.5);
                if ($u < 0 || $v < 0 || $u >= $pw || $v >= $ph) {
                    continue;
                }
                $rgb = imagecolorat($page, (int) $u, (int) $v);
                // Gradual lighting falloff across the sheet.
                $shade = $light * (1.0 - 0.18 * ($x / $cw));
                $r = (int) ((($rgb >> 16) & 0xFF) * $shade);
                $g = (int) ((($rgb >> 8) & 0xFF) * $shade);
                $b = (int) (($rgb & 0xFF) * $shade);
                imagesetpixel($canvas, $x, $y, (min(255, $r) << 16) | (min(255, $g) << 8) | min(255, $b));
            }
        }

        return ['image' => $canvas, 'quad' => $quad];
    }

    public static function jpeg(GdImage $img, int $quality = 90): string
    {
        ob_start();
        imagejpeg($img, null, $quality);

        return (string) ob_get_clean();
    }

    public static function png(GdImage $img): string
    {
        ob_start();
        imagepng($img);

        return (string) ob_get_clean();
    }

    public static function copy(GdImage $img): GdImage
    {
        $copy = imagecreatetruecolor(imagesx($img), imagesy($img));
        imagecopy($copy, $img, 0, 0, 0, 0, imagesx($img), imagesy($img));

        return $copy;
    }
}
