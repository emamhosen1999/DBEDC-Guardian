<?php

namespace App\Support\Corridor;

/**
 * The corridor centreline as a measured polyline: linear referencing between chainage and position.
 * Pure (no database), so the maths is testable against known values. Positions are not interpolated
 * beyond a straight segment between two real vertices - no spline, no invented bends.
 */
final class Alignment
{
    private const EARTH_M = 6371008.8;

    /** @var array<int, array{lat: float, lng: float, chainage_m: int}> */
    private array $points;

    /** @param array<int, array{lat: float|int|string, lng: float|int|string, chainage_m: int|float|string}> $points */
    public function __construct(array $points)
    {
        $normalised = array_map(fn (array $p): array => [
            'lat' => (float) $p['lat'], 'lng' => (float) $p['lng'], 'chainage_m' => (int) round((float) $p['chainage_m']),
        ], array_values($points));
        usort($normalised, fn (array $a, array $b): int => $a['chainage_m'] <=> $b['chainage_m']);
        $this->points = $normalised;
    }

    /** @return array<int, array{lat: float, lng: float, chainage_m: int}> */
    public function points(): array
    {
        return $this->points;
    }

    public function isUsable(): bool
    {
        return count($this->points) >= 2;
    }

    public function startM(): int
    {
        return $this->points[0]['chainage_m'] ?? 0;
    }

    public function lengthM(): int
    {
        return $this->points === [] ? 0 : $this->points[array_key_last($this->points)]['chainage_m'];
    }

    /** Position of a chainage (metres), or null outside the measured line. */
    public function pointAt(int $metres): ?array
    {
        if (! $this->isUsable() || $metres < $this->startM() || $metres > $this->lengthM()) {
            return null;
        }
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $a = $this->points[$i - 1];
            $b = $this->points[$i];
            if ($metres <= $b['chainage_m']) {
                $span = $b['chainage_m'] - $a['chainage_m'];
                $t = $span > 0 ? ($metres - $a['chainage_m']) / $span : 0.0;

                return ['lat' => $a['lat'] + ($b['lat'] - $a['lat']) * $t, 'lng' => $a['lng'] + ($b['lng'] - $a['lng']) * $t];
            }
        }

        return null;
    }

    /**
     * The line between two chainages, vertices included, as [lat, lng] pairs; empty outside the line.
     *
     * @return array<int, array{0: float, 1: float}>
     */
    public function slice(int $from, int $to): array
    {
        if (! $this->isUsable()) {
            return [];
        }
        [$from, $to] = [max($this->startM(), min($from, $to)), min($this->lengthM(), max($from, $to))];
        $start = $this->pointAt($from);
        $end = $this->pointAt($to);
        if ($start === null || $end === null) {
            return [];
        }
        $out = [[$start['lat'], $start['lng']]];
        foreach ($this->points as $p) {
            if ($p['chainage_m'] > $from && $p['chainage_m'] < $to) {
                $out[] = [$p['lat'], $p['lng']];
            }
        }
        $out[] = [$end['lat'], $end['lng']];

        return $out;
    }

    /**
     * Nearest point on the line to a coordinate: its chainage and how far off the line it is (metres).
     *
     * @return array{chainage_m: int, offset_m: float}|null
     */
    public function project(float $lat, float $lng): ?array
    {
        if (! $this->isUsable()) {
            return null;
        }
        $best = null;
        for ($i = 1, $n = count($this->points); $i < $n; $i++) {
            $a = $this->points[$i - 1];
            $b = $this->points[$i];
            $kx = 111320 * cos(deg2rad($a['lat']));
            $ky = 110540;
            $dx = ($b['lng'] - $a['lng']) * $kx;
            $dy = ($b['lat'] - $a['lat']) * $ky;
            $len2 = $dx * $dx + $dy * $dy;
            $t = $len2 > 0 ? max(0.0, min(1.0, ((($lng - $a['lng']) * $kx) * $dx + (($lat - $a['lat']) * $ky) * $dy) / $len2)) : 0.0;
            $offset = self::haversine($lat, $lng, $a['lat'] + ($b['lat'] - $a['lat']) * $t, $a['lng'] + ($b['lng'] - $a['lng']) * $t);
            if ($best === null || $offset < $best['offset_m']) {
                $best = ['chainage_m' => (int) round($a['chainage_m'] + ($b['chainage_m'] - $a['chainage_m']) * $t), 'offset_m' => $offset];
            }
        }

        return $best;
    }

    /** Vertices thinned to at most $max (always keeping both ends), for the payload. */
    public function simplified(int $max = 400): array
    {
        $n = count($this->points);
        if ($n <= $max) {
            return $this->points;
        }
        $step = ($n - 1) / ($max - 1);
        $out = [];
        for ($i = 0; $i < $max; $i++) {
            $out[] = $this->points[(int) round($i * $step)];
        }

        return $out;
    }

    /** Metres between two coordinates (haversine, the radius dhakabypass's survey workbook used). */
    public static function haversine(float $lat1, float $lng1, float $lat2, float $lng2): float
    {
        $dLat = deg2rad($lat2 - $lat1);
        $dLng = deg2rad($lng2 - $lng1);
        $s = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLng / 2) ** 2;

        return 2 * self::EARTH_M * asin(min(1.0, sqrt($s)));
    }
}
