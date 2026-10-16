<?php

namespace App\Services\Attachments;

/**
 * Dependency-free PDF facts (page count, text operators) read from the file structure. Used by every rasteriser
 * implementation: counting pages and spotting text must work even where Ghostscript is unavailable.
 */
final class PdfInspector
{
    public static function pageCount(string $path): int
    {
        $raw = self::read($path);
        // A /Count on the page-tree root is authoritative; fall back to counting /Type /Page objects.
        if (preg_match_all('#/Type\s*/Pages\b[^>]*?/Count\s+(\d+)#s', $raw, $m) || preg_match_all('#/Count\s+(\d+)[^>]*?/Type\s*/Pages\b#s', $raw, $m)) {
            return max(array_map('intval', $m[1]));
        }

        return max(1, preg_match_all('#/Type\s*/Page(?![a-zA-Z])#', $raw));
    }

    public static function hasText(string $path): bool
    {
        $raw = self::read($path);
        $streams = [$raw];
        if (preg_match_all('#stream\r?\n(.*?)\r?\nendstream#s', $raw, $m)) {
            foreach ($m[1] as $stream) {
                $inflated = @gzuncompress($stream) ?: @gzinflate(substr($stream, 2)) ?: null;
                if ($inflated !== null && $inflated !== '') {
                    $streams[] = $inflated;
                }
            }
        }
        $glyphs = 0;
        foreach ($streams as $content) {
            // Text-showing operators inside BT..ET blocks.
            if (preg_match_all('#BT\b.*?\bET\b#s', $content, $blocks)) {
                foreach ($blocks[0] as $block) {
                    $glyphs += preg_match_all('#\((?:[^()\\\\]|\\\\.)*\)\s*Tj|\[[^\]]*\]\s*TJ|<[0-9A-Fa-f\s]+>\s*Tj#', $block);
                }
            }
        }

        return $glyphs >= 3;
    }

    private static function read(string $path): string
    {
        return (string) file_get_contents($path, false, null, 0, 40 * 1024 * 1024);
    }
}
