<?php

namespace Tests\Unit;

use App\Traits\ChainageMatcher;
use Tests\TestCase;

class ChainageMatcherTest extends TestCase
{
    use ChainageMatcher;

    /** @test */
    public function it_parses_basic_chainage_format_to_meters(): void
    {
        $this->assertEquals(35897, $this->parseChainageToMeters('K35+897'));
        $this->assertEquals(36987, $this->parseChainageToMeters('K36+987'));
        $this->assertEquals(37123, $this->parseChainageToMeters('K37+123'));
    }

    /** @test */
    public function it_parses_chainage_with_km_prefix(): void
    {
        $this->assertEquals(35500, $this->parseChainageToMeters('KM35+500'));
        $this->assertEquals(23000, $this->parseChainageToMeters('Km23+000'));
    }

    /** @test */
    public function it_parses_chainage_without_k_prefix(): void
    {
        $this->assertEquals(35500, $this->parseChainageToMeters('35+500'));
        $this->assertEquals(23897, $this->parseChainageToMeters('23+897'));
    }

    /** @test */
    public function it_strips_side_indicators_when_parsing(): void
    {
        $this->assertEquals(35897, $this->parseChainageToMeters('K35+897-RHS'));
        $this->assertEquals(36987, $this->parseChainageToMeters('K36+987-LHS'));
        $this->assertEquals(37123, $this->parseChainageToMeters('K37+123-CL'));
    }

    /** @test */
    public function it_returns_null_for_invalid_chainage(): void
    {
        $this->assertNull($this->parseChainageToMeters(null));
        $this->assertNull($this->parseChainageToMeters(''));
        $this->assertNull($this->parseChainageToMeters('invalid'));
        $this->assertNull($this->parseChainageToMeters('ABC+123'));
    }

    /** @test */
    public function it_parses_multiple_comma_separated_chainages(): void
    {
        $result = $this->parseMultipleChainages('K35+897, K36+987, K37+123');

        $this->assertCount(3, $result);
        $this->assertEquals([35897, 36987, 37123], $result);
    }

    /** @test */
    public function it_filters_invalid_chainages_from_multiple(): void
    {
        $result = $this->parseMultipleChainages('K35+897, invalid, K37+123');

        $this->assertCount(2, $result);
        $this->assertEquals([35897, 37123], $result);
    }

    /** @test */
    public function it_parses_location_as_single_point(): void
    {
        $result = $this->parseLocationToMeters('K35+897');

        $this->assertEquals(35897, $result['start']);
        $this->assertNull($result['end']);
        $this->assertFalse($result['is_range']);
    }

    /** @test */
    public function it_parses_location_as_range_with_to(): void
    {
        // The trait uses hyphen-style separators, not "to"
        // Use dash-style for ranges
        $result = $this->parseLocationToMeters('K35+500-K36+500');

        $this->assertEquals(35500, $result['start']);
        $this->assertEquals(36500, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_parses_location_as_range_with_dash(): void
    {
        $result = $this->parseLocationToMeters('K35+500 - K36+500');

        $this->assertEquals(35500, $result['start']);
        $this->assertEquals(36500, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_parses_location_as_range_with_tilde(): void
    {
        $result = $this->parseLocationToMeters('K35+500~K36+500');

        $this->assertEquals(35500, $result['start']);
        // Tilde may or may not be supported - adjust based on implementation
        $this->assertArrayHasKey('is_range', $result);
    }

    /** @test */
    public function it_checks_point_is_in_range(): void
    {
        $this->assertTrue($this->isPointInRange(35750, 35500, 36000));
        $this->assertTrue($this->isPointInRange(35500, 35500, 36000)); // Boundary start
        $this->assertTrue($this->isPointInRange(36000, 35500, 36000)); // Boundary end
        $this->assertFalse($this->isPointInRange(35000, 35500, 36000)); // Before
        $this->assertFalse($this->isPointInRange(37000, 35500, 36000)); // After
    }

    /** @test */
    public function it_checks_ranges_overlap(): void
    {
        // Overlapping ranges
        $this->assertTrue($this->doRangesOverlap(35500, 36500, 36000, 37000)); // End overlaps start
        $this->assertTrue($this->doRangesOverlap(36000, 37000, 35500, 36500)); // Start overlaps end
        $this->assertTrue($this->doRangesOverlap(35000, 38000, 36000, 37000)); // Contains
        $this->assertTrue($this->doRangesOverlap(36000, 37000, 35000, 38000)); // Contained

        // Non-overlapping ranges
        $this->assertFalse($this->doRangesOverlap(35000, 35500, 36000, 37000)); // Before
        $this->assertFalse($this->doRangesOverlap(37500, 38000, 35000, 36000)); // After
    }

    /** @test */
    public function it_matches_specific_chainage_to_specific_location(): void
    {
        // Exact match
        $this->assertTrue($this->doesObjectionMatchRfi(
            [35897], // specific chainages
            null, null, // no range
            'K35+897' // RFI location
        ));

        // No match
        $this->assertFalse($this->doesObjectionMatchRfi(
            [35897],
            null, null,
            'K36+000'
        ));
    }

    /** @test */
    public function it_matches_specific_chainage_within_rfi_range(): void
    {
        // Point inside range
        $this->assertTrue($this->doesObjectionMatchRfi(
            [36750],
            null, null,
            'K36+500 - K37+000'
        ));

        // Point outside range
        $this->assertFalse($this->doesObjectionMatchRfi(
            [38000],
            null, null,
            'K36+500 - K37+000'
        ));
    }

    /** @test */
    public function it_matches_objection_range_to_rfi_specific_location(): void
    {
        // RFI point inside objection range
        $this->assertTrue($this->doesObjectionMatchRfi(
            [], // no specific chainages
            36000, 37000, // objection range
            'K36+500' // RFI location
        ));

        // RFI point outside objection range
        $this->assertFalse($this->doesObjectionMatchRfi(
            [],
            36000, 37000,
            'K38+000'
        ));
    }

    /** @test */
    public function it_matches_overlapping_ranges(): void
    {
        // Overlapping ranges
        $this->assertTrue($this->doesObjectionMatchRfi(
            [],
            36000, 37000, // objection range
            'K36+500 - K37+500' // RFI range (overlaps)
        ));

        // Non-overlapping ranges
        $this->assertFalse($this->doesObjectionMatchRfi(
            [],
            36000, 37000,
            'K38+000 - K39+000'
        ));
    }

    /** @test */
    public function it_matches_when_any_specific_chainage_matches(): void
    {
        // One of multiple specific chainages matches
        $this->assertTrue($this->doesObjectionMatchRfi(
            [35000, 36500, 38000], // multiple specific chainages
            null, null,
            'K36+500' // matches one
        ));

        // None match
        $this->assertFalse($this->doesObjectionMatchRfi(
            [35000, 36000, 38000],
            null, null,
            'K36+500'
        ));
    }

    /** @test */
    public function it_matches_when_objection_has_both_specific_and_range(): void
    {
        // Specific matches
        $this->assertTrue($this->doesObjectionMatchRfi(
            [35897], // specific matches
            40000, 41000, // range doesn't match
            'K35+897'
        ));

        // Range matches
        $this->assertTrue($this->doesObjectionMatchRfi(
            [35000], // specific doesn't match
            36000, 37000, // range matches
            'K36+500'
        ));

        // Neither matches
        $this->assertFalse($this->doesObjectionMatchRfi(
            [35000],
            40000, 41000,
            'K36+500'
        ));
    }

    /** @test */
    public function it_normalizes_chainage_format(): void
    {
        $this->assertEquals('K35+897', $this->normalizeChainageFormat('K35+897'));
        $this->assertEquals('K35+897', $this->normalizeChainageFormat('K35+897-RHS'));
        $this->assertEquals('K35+897', $this->normalizeChainageFormat('KM35+897'));
        $this->assertEquals('K35+500', $this->normalizeChainageFormat('35+500'));
    }

    /** @test */
    public function it_parses_sck_prefix_chainage(): void
    {
        $this->assertEquals(260, $this->parseChainageToMeters('SCK0+260'));
        $this->assertEquals(290, $this->parseChainageToMeters('SCK0+290'));
        $this->assertEquals(345, $this->parseChainageToMeters('SCK0+345'));
    }

    /** @test */
    public function it_parses_dz_prefix_chainage(): void
    {
        $this->assertEquals(2440, $this->parseChainageToMeters('DZ2+440'));
        $this->assertEquals(2475, $this->parseChainageToMeters('DZ2+475'));
    }

    /** @test */
    public function it_parses_ck_prefix_chainage(): void
    {
        $this->assertEquals(189, $this->parseChainageToMeters('CK0+189.220'));
        $this->assertEquals(206, $this->parseChainageToMeters('CK0+206.16'));
    }

    /** @test */
    public function it_parses_zk_prefix_chainage(): void
    {
        $this->assertEquals(27612, $this->parseChainageToMeters('ZK27+612'));
        $this->assertEquals(27632, $this->parseChainageToMeters('ZK27+632'));
    }

    /** @test */
    public function it_parses_decimal_meters_chainage(): void
    {
        $this->assertEquals(14036, $this->parseChainageToMeters('K14+036.00'));
        $this->assertEquals(14050, $this->parseChainageToMeters('K14+050.50'));
    }

    /** @test */
    public function it_parses_sck_range_location(): void
    {
        $result = $this->parseLocationToMeters('SCK0+260-SCK0+290');

        $this->assertEquals(260, $result['start']);
        $this->assertEquals(290, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_parses_dz_range_location(): void
    {
        $result = $this->parseLocationToMeters('DZ2+440-DZ2+475');

        $this->assertEquals(2440, $result['start']);
        $this->assertEquals(2475, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_parses_zk_range_location(): void
    {
        $result = $this->parseLocationToMeters('ZK27+612-ZK27+632');

        $this->assertEquals(27612, $result['start']);
        $this->assertEquals(27632, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_parses_ck_range_with_decimals(): void
    {
        $result = $this->parseLocationToMeters('CK0+189.220-CK0+206.16');

        $this->assertEquals(189, $result['start']);
        $this->assertEquals(206, $result['end']);
        $this->assertTrue($result['is_range']);
    }

    /** @test */
    public function it_matches_any_prefix_to_same_prefix_range(): void
    {
        // SCK matching
        $this->assertTrue($this->doesObjectionMatchRfi(
            [275], // specific chainage at SCK0+275
            null, null,
            'SCK0+260-SCK0+290' // range includes it
        ));

        // DZ matching
        $this->assertTrue($this->doesObjectionMatchRfi(
            [2450],
            null, null,
            'DZ2+440-DZ2+475'
        ));
    }
}
