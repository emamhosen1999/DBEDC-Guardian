<?php

namespace App\Support\Attendance;

use App\Models\User;
use Illuminate\Support\Facades\File;

/**
 * Small SQUARE avatar PNGs for the attendance exports (Excel drawings, PDF images), sized to the register's
 * row height: 21 px shown, rendered at 2x (42 px) for crispness. No circle and no rounded corners.
 *
 * The real profile photo is centre-cropped to a square (never stretched) once and cached on disk,
 * keyed by employee id plus the photo's id and updated timestamp, so a 200-employee export does
 * not resize 200 photos every time. Employees without a (readable) photo get initials on the
 * app's avatar tint; never a stock or invented photo.
 */
class RegisterAvatar
{
    /** 2x source; shown at SIZE / 2 = 21 px, the height of a 15.75 pt register row. */
    public const SIZE = 42;

    /** Bumped when the look changes so cached renders are replaced (v2: square, 2x). */
    private const LOOK = 'sq2';

    /** Cyber Avatar: accent at 14% over white, accent-11 text. */
    private const TINT = [0xE6, 0xEA, 0xFA];

    private const INK = [0x3A, 0x5B, 0xC7];

    /** Renders performed by this instance (cache misses); the cache-reuse test reads it. */
    public int $rendered = 0;

    public function __construct(private ?string $dir = null)
    {
        $this->dir ??= storage_path('app/register-avatars');
    }

    public static function initials(string $name): string
    {
        $parts = array_slice(array_values(array_filter(preg_split('/\s+/u', trim($name)) ?: [])), 0, 2);

        return $parts === [] ? '?' : implode('', array_map(fn ($p) => mb_strtoupper(mb_substr($p, 0, 1)), $parts));
    }

    /** Absolute path of the cached PNG for this employee (rendered when missing). */
    public function pathFor(User $user): ?string
    {
        if (! extension_loaded('gd')) {
            return null;
        }
        $media = null;
        try {
            $media = $user->getFirstMedia('profile_images');
        } catch (\Throwable) {
            $media = null;
        }
        $source = null;
        $version = self::LOOK.'-initials-'.substr(md5(self::initials((string) $user->name)), 0, 8);
        if ($media) {
            try {
                $candidate = $media->getPath();
                if (is_file($candidate) && is_readable($candidate)) {
                    $source = $candidate;
                    $version = self::LOOK.'-'.$media->id.'-'.($media->updated_at?->timestamp ?? filemtime($candidate));
                }
            } catch (\Throwable) {
                $source = null;
            }
        }
        File::ensureDirectoryExists($this->dir);
        $target = $this->dir.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $user->id).'-'.$version.'.png';
        if (is_file($target)) {
            return $target;
        }
        $img = $source ? $this->fromPhoto($source) : null;
        $img ??= $this->fromInitials((string) $user->name);
        if ($img === null) {
            return null;
        }
        imagesavealpha($img, true);
        imagepng($img, $target, 9);
        imagedestroy($img);
        // A re-uploaded photo leaves older renders of this employee behind; drop them.
        foreach (glob($this->dir.'/'.preg_replace('/[^A-Za-z0-9_-]/', '_', (string) $user->id).'-*.png') ?: [] as $old) {
            if ($old !== $target) {
                @unlink($old);
            }
        }
        $this->rendered++;

        return $target;
    }

    private function fromPhoto(string $path): ?\GdImage
    {
        $data = @file_get_contents($path);
        $src = $data === false ? false : @imagecreatefromstring($data);
        if (! $src) {
            return null;
        }
        $w = imagesx($src);
        $h = imagesy($src);
        $side = min($w, $h);
        $dst = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($dst, false);
        imagesavealpha($dst, true);
        imagefill($dst, 0, 0, imagecolorallocatealpha($dst, 0, 0, 0, 127));
        imagecopyresampled($dst, $src, 0, 0, intdiv($w - $side, 2), intdiv($h - $side, 2), self::SIZE, self::SIZE, $side, $side);
        imagedestroy($src);

        return $dst;
    }

    private function fromInitials(string $name): ?\GdImage
    {
        $img = imagecreatetruecolor(self::SIZE, self::SIZE);
        imagealphablending($img, false);
        imagesavealpha($img, true);
        imagefill($img, 0, 0, imagecolorallocatealpha($img, 0, 0, 0, 127));
        imagealphablending($img, true);
        imagefilledrectangle($img, 0, 0, self::SIZE, self::SIZE, imagecolorallocate($img, ...self::TINT));
        $font = resource_path('fonts/register/LiberationSansNarrow-Bold.ttf');
        $text = self::initials($name);
        $ink = imagecolorallocate($img, ...self::INK);
        if (function_exists('imagettftext') && is_file($font)) {
            $size = 17;
            $box = imagettfbbox($size, 0, $font, $text);
            $tw = $box[2] - $box[0];
            $th = $box[1] - $box[7];
            imagettftext($img, $size, 0, (int) round((self::SIZE - $tw) / 2 - $box[0]), (int) round((self::SIZE + $th) / 2 - $box[1]), $ink, $font, $text);
        } else {
            imagestring($img, 3, 8, 11, $text, $ink);
        }

        return $img;
    }
}
