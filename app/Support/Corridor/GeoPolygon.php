<?php

namespace App\Support\Corridor;

use InvalidArgumentException;

/**
 * GeoJSON Polygons for `area` jurisdictions: validate, simplify, test for overlap. Coordinates are [lng, lat] as in GeoJSON.
 * No drawing in Phase 1: the polygon comes from an existing geofence or an uploaded GeoJSON.
 */
final class GeoPolygon
{
    /** A sane Bangladesh bounding box (a typo that puts the polygon in the sea or abroad is refused). */
    public const BBOX = ['min_lng' => 88.0, 'max_lng' => 92.8, 'min_lat' => 20.5, 'max_lat' => 26.7];

    public const MAX_VERTICES = 200;

    /**
     * A GeoJSON Polygon / Feature / FeatureCollection (array or JSON string) as a simplified Polygon geometry.
     *
     * @return array{type: string, coordinates: array<int, array<int, array{0: float, 1: float}>>}
     */
    public static function normalize(mixed $input): array
    {
        if (is_string($input)) {
            $input = json_decode($input, true);
        }
        if (! is_array($input)) {
            throw new InvalidArgumentException('The area is not valid GeoJSON.');
        }
        if (($input['type'] ?? null) === 'FeatureCollection') {
            $input = $input['features'][0] ?? null;
        }
        if (is_array($input) && ($input['type'] ?? null) === 'Feature') {
            $input = $input['geometry'] ?? null;
        }
        if (! is_array($input) || ($input['type'] ?? null) !== 'Polygon' || ! isset($input['coordinates'][0]) || ! is_array($input['coordinates'][0])) {
            throw new InvalidArgumentException('The area must be a GeoJSON Polygon.');
        }
        $ring = [];
        foreach ($input['coordinates'][0] as $point) {
            if (! is_array($point) || ! isset($point[0], $point[1]) || ! is_numeric($point[0]) || ! is_numeric($point[1])) {
                throw new InvalidArgumentException('Every polygon vertex needs a longitude and a latitude.');
            }
            $ring[] = [(float) $point[0], (float) $point[1]];
        }
        if (count($ring) < 4) {
            throw new InvalidArgumentException('A polygon needs at least three corners.');
        }
        if ($ring[0] !== $ring[count($ring) - 1]) {
            throw new InvalidArgumentException('The polygon ring is not closed: the first and last point must be the same.');
        }
        foreach ($ring as [$lng, $lat]) {
            if ($lng < self::BBOX['min_lng'] || $lng > self::BBOX['max_lng'] || $lat < self::BBOX['min_lat'] || $lat > self::BBOX['max_lat']) {
                throw new InvalidArgumentException('The polygon lies outside Bangladesh.');
            }
        }

        return ['type' => 'Polygon', 'coordinates' => [self::simplify($ring)]];
    }

    /** A ring from [lat, lng] pairs (the attendance geofences' native order). */
    public static function fromLatLng(array $points): array
    {
        $ring = array_map(fn (array $p): array => [(float) $p[1], (float) $p[0]], $points);
        if ($ring !== [] && $ring[0] !== $ring[count($ring) - 1]) {
            $ring[] = $ring[0];
        }

        return ['type' => 'Polygon', 'coordinates' => [$ring]];
    }

    /**
     * Douglas-Peucker on the ring, tolerance doubled until at most MAX_VERTICES remain. Ring stays closed.
     *
     * @param  array<int, array{0: float, 1: float}>  $ring
     * @return array<int, array{0: float, 1: float}>
     */
    public static function simplify(array $ring): array
    {
        if (count($ring) <= self::MAX_VERTICES) {
            return $ring;
        }
        $open = array_slice($ring, 0, -1);
        $tol = 0.00002;
        do {
            $kept = self::dp($open, $tol);
            $tol *= 2;
        } while (count($kept) + 1 > self::MAX_VERTICES);
        $kept[] = $kept[0];

        return $kept;
    }

    /** @param array<int, array{0: float, 1: float}> $pts */
    private static function dp(array $pts, float $tol): array
    {
        $n = count($pts);
        if ($n < 3) {
            return $pts;
        }
        $keep = array_fill(0, $n, false);
        $keep[0] = $keep[$n - 1] = true;
        $stack = [[0, $n - 1]];
        while ($stack) {
            [$a, $b] = array_pop($stack);
            $max = 0.0;
            $idx = -1;
            for ($i = $a + 1; $i < $b; $i++) {
                $d = self::distance($pts[$i], $pts[$a], $pts[$b]);
                if ($d > $max) {
                    $max = $d;
                    $idx = $i;
                }
            }
            if ($idx !== -1 && $max > $tol) {
                $keep[$idx] = true;
                $stack[] = [$a, $idx];
                $stack[] = [$idx, $b];
            }
        }

        return array_values(array_intersect_key($pts, array_filter($keep)));
    }

    private static function distance(array $p, array $a, array $b): float
    {
        $dx = $b[0] - $a[0];
        $dy = $b[1] - $a[1];
        if ($dx == 0 && $dy == 0) {
            return hypot($p[0] - $a[0], $p[1] - $a[1]);
        }
        $t = max(0, min(1, (($p[0] - $a[0]) * $dx + ($p[1] - $a[1]) * $dy) / ($dx * $dx + $dy * $dy)));

        return hypot($p[0] - ($a[0] + $t * $dx), $p[1] - ($a[1] + $t * $dy));
    }

    /** True when the two polygons share any area (edges cross, or one contains a vertex of the other). */
    public static function overlaps(array $a, array $b): bool
    {
        $ra = $a['coordinates'][0] ?? [];
        $rb = $b['coordinates'][0] ?? [];
        if ($ra === [] || $rb === []) {
            return false;
        }
        for ($i = 0, $n = count($ra) - 1; $i < $n; $i++) {
            for ($j = 0, $m = count($rb) - 1; $j < $m; $j++) {
                if (self::segmentsCross($ra[$i], $ra[$i + 1], $rb[$j], $rb[$j + 1])) {
                    return true;
                }
            }
        }

        return self::contains($ra, $rb[0]) || self::contains($rb, $ra[0]);
    }

    /** @param array<int, array{0: float, 1: float}> $ring */
    public static function contains(array $ring, array $pt): bool
    {
        $inside = false;
        for ($i = 0, $j = count($ring) - 1; $i < count($ring); $j = $i++) {
            if ((($ring[$i][1] > $pt[1]) !== ($ring[$j][1] > $pt[1]))
                && ($pt[0] < ($ring[$j][0] - $ring[$i][0]) * ($pt[1] - $ring[$i][1]) / (($ring[$j][1] - $ring[$i][1]) ?: 1e-12) + $ring[$i][0])) {
                $inside = ! $inside;
            }
        }

        return $inside;
    }

    private static function segmentsCross(array $p1, array $p2, array $p3, array $p4): bool
    {
        $d = fn (array $a, array $b, array $c): float => ($c[0] - $a[0]) * ($b[1] - $a[1]) - ($b[0] - $a[0]) * ($c[1] - $a[1]);
        $d1 = $d($p3, $p4, $p1);
        $d2 = $d($p3, $p4, $p2);
        $d3 = $d($p1, $p2, $p3);
        $d4 = $d($p1, $p2, $p4);

        return (($d1 > 0 && $d2 < 0) || ($d1 < 0 && $d2 > 0)) && (($d3 > 0 && $d4 < 0) || ($d3 < 0 && $d4 > 0));
    }
}
