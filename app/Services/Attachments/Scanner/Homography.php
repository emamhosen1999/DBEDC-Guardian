<?php

namespace App\Services\Attachments\Scanner;

/** Projective transform between a rectangle (0,0)-(w,h) and an arbitrary quadrilateral (TL, TR, BR, BL). */
final class Homography
{
    /** @param list<float> $m the 8 coefficients h11..h32 (h33 = 1) */
    private function __construct(private readonly array $m) {}

    /**
     * Mapping from the rectangle w x h onto the quad.
     *
     * @param  list<array{0: float, 1: float}>  $quad  TL, TR, BR, BL
     */
    public static function rectToQuad(float $w, float $h, array $quad): self
    {
        return self::fromPoints([[0, 0], [$w, 0], [$w, $h], [0, $h]], $quad);
    }

    /**
     * The inverse direction: the quad onto the rectangle w x h.
     *
     * @param  list<array{0: float, 1: float}>  $quad  TL, TR, BR, BL
     */
    public static function quadToRect(array $quad, float $w, float $h): self
    {
        return self::fromPoints($quad, [[0, 0], [$w, 0], [$w, $h], [0, $h]]);
    }

    private static function fromPoints(array $src, array $quad): self
    {
        $a = [];
        $b = [];
        for ($i = 0; $i < 4; $i++) {
            [$x, $y] = $src[$i];
            [$u, $v] = $quad[$i];
            $a[] = [$x, $y, 1, 0, 0, 0, -$x * $u, -$y * $u];
            $b[] = $u;
            $a[] = [0, 0, 0, $x, $y, 1, -$x * $v, -$y * $v];
            $b[] = $v;
        }

        return new self(self::solve($a, $b));
    }

    /** @return array{0: float, 1: float} */
    public function map(float $x, float $y): array
    {
        [$a, $b, $c, $d, $e, $f, $g, $h] = $this->m;
        $den = $g * $x + $h * $y + 1.0;

        return [($a * $x + $b * $y + $c) / $den, ($d * $x + $e * $y + $f) / $den];
    }

    /** @return list<float> */
    public function coefficients(): array
    {
        return $this->m;
    }

    /** Gaussian elimination with partial pivoting for the 8x8 system. */
    private static function solve(array $a, array $b): array
    {
        $n = 8;
        for ($i = 0; $i < $n; $i++) {
            $a[$i][] = $b[$i];
        }
        for ($col = 0; $col < $n; $col++) {
            $pivot = $col;
            for ($r = $col + 1; $r < $n; $r++) {
                if (abs($a[$r][$col]) > abs($a[$pivot][$col])) {
                    $pivot = $r;
                }
            }
            if (abs($a[$pivot][$col]) < 1e-12) {
                throw new \RuntimeException('Degenerate quadrilateral.');
            }
            [$a[$col], $a[$pivot]] = [$a[$pivot], $a[$col]];
            for ($r = 0; $r < $n; $r++) {
                if ($r === $col) {
                    continue;
                }
                $factor = $a[$r][$col] / $a[$col][$col];
                for ($c = $col; $c <= $n; $c++) {
                    $a[$r][$c] -= $factor * $a[$col][$c];
                }
            }
        }
        $x = [];
        for ($i = 0; $i < $n; $i++) {
            $x[] = $a[$i][$n] / $a[$i][$i];
        }

        return $x;
    }
}
