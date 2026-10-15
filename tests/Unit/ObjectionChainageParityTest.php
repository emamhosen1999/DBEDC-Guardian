<?php

namespace Tests\Unit;

use App\Support\Corridor\Chainage;
use App\Traits\ChainageMatcher;
use Tests\TestCase;

/**
 * The objections register parsed chainages with its own trait while the rest of the system uses the shared Chainage service
 * (Phase 1). Proof that, for every K-style chainage the registers hold, the two give the same metres; the only differences are
 * the lenient unprefixed forms (bare numbers), which are listed here on purpose so nobody changes them by accident.
 * The dead parseChainageToMetersLegacy duplicate was removed.
 */
class ObjectionChainageParityTest extends TestCase
{
    use ChainageMatcher;

    /** @return array<string, array{0: string, 1: int}> */
    public static function kStyle(): array
    {
        return [
            'plain' => ['K5+900', 5900], 'padded' => ['K05+900', 5900], 'lower' => ['k12+000', 12000], 'short metres are metres' => ['K23+60', 23060],
            'five metres' => ['K23+5', 23005], 'km only' => ['K35', 35000], 'prefix SCK' => ['SCK0+260', 260], 'prefix CK with decimal' => ['CK0+189.220', 189],
            'spaces' => ['K 36 + 580', 36580], 'trailing side' => ['K36+580 RHS', 36580], 'dash side' => ['K36+580-LHS', 36580], 'last km' => ['K48+000', 48000],
        ];
    }

    /** @dataProvider kStyle */
    public function test_the_trait_and_the_shared_service_agree_on_every_k_style_chainage(string $text, int $metres): void
    {
        $this->assertSame($metres, Chainage::token($text), 'shared service');
        $this->assertSame($metres, $this->parseChainageToMeters($text), 'objections trait');
    }

    public function test_the_deliberate_differences_are_only_the_unprefixed_forms(): void
    {
        $this->assertNull(Chainage::token('35.897'));
        $this->assertSame(35897, $this->parseChainageToMeters('35.897'), 'the objections form also accepts a dotted kilometre');
        $this->assertNull(Chainage::token('35'));
        $this->assertSame(35000, $this->parseChainageToMeters('35'), 'a bare number in the objections form is kilometres');
    }
}
