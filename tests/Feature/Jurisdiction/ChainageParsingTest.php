<?php

namespace Tests\Feature\Jurisdiction;

use App\Services\Corridor\ChainageResolver;
use App\Support\Corridor\Chainage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/** The one chainage parser: every spelling the registers use, point or span, and the cases that must NOT be guessed. */
class ChainageParsingTest extends TestCase
{
    #[DataProvider('spellings')]
    public function test_location_text_to_metres(string $text, ?int $from, ?int $to): void
    {
        $span = (new ChainageResolver)->span($text);
        $from === null ? $this->assertNull($span) : $this->assertSame(['from' => $from, 'to' => $to], $span);
    }

    /** @return array<string, array{0: string, 1: ?int, 2: ?int}> */
    public static function spellings(): array
    {
        return [
            'K point' => ['K5+900', 5900, 5900],
            'padded K point' => ['K05+900', 5900, 5900],
            'Ch point' => ['Ch 24+500', 24500, 24500],
            'span' => ['K05+900-K06+400', 5900, 6400],
            'span spaced' => ['K05+900 - K06+400', 5900, 6400],
            'span with side' => ['K35+560-K36+120 LHS', 35560, 36120],
            'decimal metres truncate' => ['K14+036.00', 14036, 14036],
            'kilometre only' => ['K27', 27000, 27000],
            'kilometre only with place' => ['K13  TOLL STATION', 13000, 13000],
            'kilometre only with dash' => ['K-40', 40000, 40000],
            'yard' => ['K30-YARD', 30000, 30000],
            'number glued to the K is not guessed' => ['K17-396', null, null],
            'two km tokens are not guessed' => ['K03-500-K03-520', null, null],
            'three chainages are not guessed' => ["K18+094\nK20+626 K20+909", null, null],
            'free text' => ['Main gate', null, null],
            'a word ending in K is not a chainage' => ['ROCK 5', null, null],
        ];
    }

    public function test_validator_tokens_read_metres_as_metres(): void
    {
        $this->assertSame(5090, Chainage::token('K5+90'));   // K5+090, never K5+900
        $this->assertSame(5900, Chainage::token('K05+900'));
        $this->assertSame(260, Chainage::token('SCK0+260'));
        $this->assertSame(189, Chainage::token('CK0+189.220'));
        $this->assertSame(30000, Chainage::token('K30'));
        $this->assertNull(Chainage::token('nothing'));
    }
}
