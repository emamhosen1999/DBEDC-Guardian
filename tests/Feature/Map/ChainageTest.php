<?php

namespace Tests\Feature\Map;

use App\Support\Corridor\Alignment;
use App\Support\Corridor\Chainage;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Chainage maths: parsing every spelling the registers use, and linear referencing along the alignment. */
class ChainageTest extends TestCase
{
    public function test_formats_metres_as_k_plus(): void
    {
        $this->assertSame('K12+500', Chainage::format(12500));
        $this->assertSame('K0+000', Chainage::format(0));
        $this->assertSame('K47+611', Chainage::format(47611));
    }

    #[DataProvider('spellings')]
    public function test_parses_the_register_spellings(mixed $text, ?int $from, ?int $to): void
    {
        $span = Chainage::span($text);
        if ($from === null) {
            $this->assertNull($span);

            return;
        }
        $this->assertSame(['from' => $from, 'to' => $to], $span);
    }

    /** @return array<string, array{0: mixed, 1: ?int, 2: ?int}> */
    public static function spellings(): array
    {
        return [
            'K point' => ['K4+100', 4100, 4100],
            'Ch point' => ['Ch 24+500', 24500, 24500],
            'KM zero padded' => ['KM 00+000', 0, 0],
            'range with second K' => ['K4+688.30-K6+691.3', 4688, 6691],
            'range with en dash and side' => ["Km 37+120\u{2013}37+400 LHS", 37120, 37400],
            'range with "to"' => ['K4+100 to 4+320', 4100, 4320],
            'trailing text' => ['Ch 14+250 (Northbound Slow Lane)', 14250, 14250],
            'bare metres' => [23066, 23066, 23066],
            'bare metre string' => ['37120', 37120, 37120],
            'empty' => ['', null, null],
            'null' => [null, null, null],
            'free text is not guessed' => ['Main gate', null, null],
            'three chainages are ambiguous' => ['K1+000 K2+000 K3+000', null, null],
            'metre part over 999 is not a chainage' => ['K1+1500', null, null],
            'beyond the corridor' => ['K99+000', null, null],
        ];
    }

    public function test_a_span_is_not_a_point(): void
    {
        $this->assertNull(Chainage::parse('K4+100 to 4+320'));
        $this->assertSame(4100, Chainage::parse('K4+100'));
    }

    public function test_decimal_kilometres(): void
    {
        $this->assertSame(12345, Chainage::fromKm('12.345'));
        $this->assertNull(Chainage::fromKm(null));
        $this->assertNull(Chainage::fromKm('abc'));
    }

    private function line(): Alignment
    {
        // Straight north-south line, 1 km per 0.009 degrees of latitude (about).
        return new Alignment([
            ['lat' => 24.000, 'lng' => 90.0, 'chainage_m' => 0],
            ['lat' => 23.964, 'lng' => 90.0, 'chainage_m' => 4000],
            ['lat' => 23.910, 'lng' => 90.0, 'chainage_m' => 10000],
        ]);
    }

    public function test_k12_500_style_lookup_lands_between_the_bracketing_vertices(): void
    {
        $at = $this->line()->pointAt(7000);
        $this->assertEqualsWithDelta(23.937, $at['lat'], 1e-9);
        $this->assertEqualsWithDelta(90.0, $at['lng'], 1e-9);
        $this->assertNull($this->line()->pointAt(10001));
        $this->assertEqualsWithDelta(23.964, $this->line()->pointAt(4000)['lat'], 1e-9);
    }

    public function test_project_round_trips_a_position_to_its_chainage(): void
    {
        $line = $this->line();
        $at = $line->pointAt(6500);
        $hit = $line->project($at['lat'], $at['lng']);
        $this->assertEqualsWithDelta(6500, $hit['chainage_m'], 1);
        $this->assertLessThan(1.0, $hit['offset_m']);

        $off = $line->project($at['lat'], $at['lng'] + 0.001); // about 100 m east of the line
        $this->assertEqualsWithDelta(6500, $off['chainage_m'], 5);
        $this->assertEqualsWithDelta(101, $off['offset_m'], 5);
    }

    public function test_slice_includes_the_interior_vertices(): void
    {
        $slice = $this->line()->slice(2000, 8000);
        $this->assertCount(3, $slice);
        $this->assertEqualsWithDelta(23.964, $slice[1][0], 1e-9);
        $this->assertSame([], $this->line()->slice(20000, 30000));
    }

    public function test_haversine_matches_the_survey_workbook_radius(): void
    {
        $this->assertEqualsWithDelta(2314, Alignment::haversine(23.986737, 90.362246, 23.977568, 90.380874), 200);
    }
}
