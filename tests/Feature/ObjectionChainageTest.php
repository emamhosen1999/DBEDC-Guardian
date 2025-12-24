<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\RfiObjection;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ObjectionChainageTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /** @test */
    public function it_can_create_objection_with_multiple_specific_chainages(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('objections.store'), [
            'title' => 'Test Objection with Multiple Chainages',
            'category' => 'quality',
            'specific_chainages' => 'K35+897, K36+987, K37+123',
            'description' => 'Testing multiple specific chainages',
            'reason' => 'Test reason',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('rfi_objections', [
            'title' => 'Test Objection with Multiple Chainages',
        ]);

        $objection = RfiObjection::first();
        $this->assertCount(3, $objection->specificChainages);
        $this->assertEquals(['K35+897', 'K36+987', 'K37+123'], $objection->specificChainages->pluck('chainage')->toArray());
    }

    /** @test */
    public function it_can_create_objection_with_chainage_range(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('objections.store'), [
            'title' => 'Test Objection with Range',
            'category' => 'safety',
            'chainage_range_from' => 'K36+580',
            'chainage_range_to' => 'K37+540',
            'description' => 'Testing chainage range',
            'reason' => 'Test reason',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);

        $objection = RfiObjection::first();
        $rangeChainages = $objection->rangeChainages;
        $this->assertCount(2, $rangeChainages);

        $rangeStart = $rangeChainages->where('entry_type', 'range_start')->first();
        $rangeEnd = $rangeChainages->where('entry_type', 'range_end')->first();

        $this->assertEquals('K36+580', $rangeStart->chainage);
        $this->assertEquals('K37+540', $rangeEnd->chainage);
    }

    /** @test */
    public function it_can_create_objection_with_both_specific_and_range_chainages(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('objections.store'), [
            'title' => 'Test Objection with Both',
            'category' => 'other',
            'specific_chainages' => 'K35+897, K36+987',
            'chainage_range_from' => 'K38+000',
            'chainage_range_to' => 'K39+000',
            'description' => 'Testing both types',
            'reason' => 'Test reason',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);

        $objection = RfiObjection::first();
        $this->assertCount(2, $objection->specificChainages);
        $this->assertCount(2, $objection->rangeChainages);
        $this->assertCount(4, $objection->chainages); // 2 specific + 2 range
    }

    /** @test */
    public function it_strips_side_indicators_from_chainages(): void
    {
        $this->actingAs($this->user);

        $response = $this->postJson(route('objections.store'), [
            'title' => 'Test with Side Indicators',
            'category' => 'quality',
            'specific_chainages' => 'K35+897-RHS, K36+987-LHS',
            'description' => 'Testing side indicator stripping',
            'reason' => 'Test reason',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);

        $objection = RfiObjection::first();
        $chainages = $objection->specificChainages->pluck('chainage')->toArray();

        // Side indicators should be stripped
        $this->assertEquals(['K35+897', 'K36+987'], $chainages);
    }

    /** @test */
    public function it_matches_objection_specific_chainage_to_rfi_specific_location(): void
    {
        $this->actingAs($this->user);

        // Create objection with specific chainages
        $objection = RfiObjection::factory()->create([
            'created_by' => $this->user->id,
        ]);
        $objection->addSpecificChainages('K35+897, K36+500');

        // Create RFI with matching location
        $rfi = DailyWork::factory()->create([
            'location' => 'K35+897',
            'incharge' => $this->user->id,
        ]);

        $this->assertTrue($objection->matchesRfiLocation($rfi->location));
    }

    /** @test */
    public function it_matches_objection_specific_chainage_within_rfi_range(): void
    {
        $this->actingAs($this->user);

        // Create objection with specific chainages
        $objection = RfiObjection::factory()->create([
            'created_by' => $this->user->id,
        ]);
        $objection->addSpecificChainages('K36+750');

        // RFI with range that contains the specific chainage
        $rfiLocation = 'K36+500 to K37+000';

        $this->assertTrue($objection->matchesRfiLocation($rfiLocation));
    }

    /** @test */
    public function it_matches_objection_range_overlapping_with_rfi_range(): void
    {
        $this->actingAs($this->user);

        // Create objection with range
        $objection = RfiObjection::factory()->create([
            'created_by' => $this->user->id,
        ]);
        $objection->setRangeChainages('K36+000', 'K37+000');

        // RFI with overlapping range
        $rfiLocation = 'K36+500 to K37+500';

        $this->assertTrue($objection->matchesRfiLocation($rfiLocation));
    }

    /** @test */
    public function it_updates_objection_chainages(): void
    {
        $this->actingAs($this->user);

        $objection = RfiObjection::factory()->create([
            'created_by' => $this->user->id,
        ]);
        $objection->addSpecificChainages('K35+000, K36+000');

        $response = $this->putJson(route('objections.update', $objection), [
            'title' => $objection->title,
            'description' => $objection->description,
            'reason' => $objection->reason,
            'specific_chainages' => 'K37+000, K38+000, K39+000',
        ]);

        $response->assertStatus(200);

        $objection->refresh();
        $this->assertCount(3, $objection->specificChainages);
        $this->assertEquals(['K37+000', 'K38+000', 'K39+000'], $objection->specificChainages->pluck('chainage')->toArray());
    }

    /** @test */
    public function it_suggests_rfis_based_on_specific_chainages(): void
    {
        $this->actingAs($this->user);

        // Create RFIs with various locations
        $rfi1 = DailyWork::factory()->create(['location' => 'K35+897', 'incharge' => $this->user->id]);
        $rfi2 = DailyWork::factory()->create(['location' => 'K36+987', 'incharge' => $this->user->id]);
        $rfi3 = DailyWork::factory()->create(['location' => 'K40+000', 'incharge' => $this->user->id]);

        $response = $this->getJson(route('objections.suggest-rfis', [
            'chainage_from' => 'K35+897, K36+987',
        ]));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('specific', $data['match_type']);
        $this->assertCount(2, $data['rfis']); // Should match rfi1 and rfi2, not rfi3
    }

    /** @test */
    public function it_suggests_rfis_based_on_chainage_range(): void
    {
        $this->actingAs($this->user);

        // Create RFIs within and outside the range
        $rfi1 = DailyWork::factory()->create(['location' => 'K36+700', 'incharge' => $this->user->id]);
        $rfi2 = DailyWork::factory()->create(['location' => 'K37+200', 'incharge' => $this->user->id]);
        $rfi3 = DailyWork::factory()->create(['location' => 'K40+000', 'incharge' => $this->user->id]);

        $response = $this->getJson(route('objections.suggest-rfis', [
            'chainage_from' => 'K36+500',
            'chainage_to' => 'K37+500',
        ]));

        $response->assertStatus(200);
        $data = $response->json();

        $this->assertEquals('range', $data['match_type']);
        $this->assertCount(2, $data['rfis']); // Should match rfi1 and rfi2, not rfi3
    }

    /** @test */
    public function it_returns_chainage_summary_for_objection(): void
    {
        $this->actingAs($this->user);

        $objection = RfiObjection::factory()->create([
            'created_by' => $this->user->id,
        ]);
        $objection->addSpecificChainages('K35+897, K36+987');
        $objection->setRangeChainages('K38+000', 'K39+000');

        $summary = $objection->getChainageSummary();

        $this->assertArrayHasKey('specific_chainages', $summary);
        $this->assertArrayHasKey('chainage_range', $summary);
        $this->assertEquals(['K35+897', 'K36+987'], $summary['specific_chainages']);
        $this->assertEquals(['from' => 'K38+000', 'to' => 'K39+000'], $summary['chainage_range']);
    }
}
