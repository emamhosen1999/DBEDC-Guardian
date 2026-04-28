<?php

namespace Tests\Feature;

use App\Models\DailyWork;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DailyWorkEnhancedValidationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
        $this->actingAs($this->user, 'sanctum');
    }

    /** @test */
    public function it_validates_enhanced_location_formats()
    {
        // Test valid location formats
        $validLocations = [
            'K14',
            'K14+500',
            'K14+500.5',
            'K14-K15',
            'K14+500-K15+200',
            'SK14+900',
            'DK14+500',
            'K14+500R',
            'K14+500L',
        ];

        foreach ($validLocations as $location) {
            $response = $this->postJson('/add-daily-work', [
                'date' => now()->format('Y-m-d'),
                'number' => 'TEST-' . uniqid(),
                'planned_time' => '08:00',
                'status' => DailyWork::STATUS_NEW,
                'type' => DailyWork::TYPE_STRUCTURE,
                'description' => 'Test description with enough characters to pass validation',
                'location' => $location,
                'side' => 'TR-R',
            ]);

            // Should not fail on location validation
            $response->assertJsonMissingValidationErrors(['location']);
        }
    }

    /** @test */
    public function it_rejects_invalid_location_formats()
    {
        $invalidLocations = [
            'invalid',
            'K',
            '14+500',
            'K14+',
            'K14+abc',
            'X14+500', // Invalid prefix
        ];

        foreach ($invalidLocations as $location) {
            $response = $this->postJson('/add-daily-work', [
                'date' => now()->format('Y-m-d'),
                'number' => 'TEST-' . uniqid(),
                'planned_time' => '08:00',
                'status' => DailyWork::STATUS_NEW,
                'type' => DailyWork::TYPE_STRUCTURE,
                'description' => 'Test description with enough characters to pass validation',
                'location' => $location,
                'side' => 'TR-R',
            ]);

            $response->assertJsonValidationErrors(['location']);
        }
    }

    /** @test */
    public function it_requires_qty_layer_for_embankment_work()
    {
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '08:00',
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_EMBANKMENT,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'K14+500',
            'side' => 'TR-R',
            // qty_layer is missing
        ]);

        $response->assertJsonValidationErrors(['qty_layer']);
    }

    /** @test */
    public function it_validates_embankment_work_side_restrictions()
    {
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '08:00',
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_EMBANKMENT,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'K14+500',
            'side' => 'SR-R', // Invalid side for embankment
            'qty_layer' => '1.5',
        ]);

        $response->assertJsonValidationErrors(['side']);
    }

    /** @test */
    public function it_validates_pavement_work_location_requirements()
    {
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '08:00',
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_PAVEMENT,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'K14', // Should require specific chainage
            'side' => 'TR-R',
        ]);

        $response->assertJsonValidationErrors(['location']);
    }

    /** @test */
    public function it_validates_structure_work_location_requirements()
    {
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '08:00',
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_STRUCTURE,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'invalid', // Should require K format
            'side' => 'TR-R',
        ]);

        $response->assertJsonValidationErrors(['location']);
    }

    /** @test */
    public function it_validates_time_formats()
    {
        // Invalid time format
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '25:00', // Invalid hour
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_STRUCTURE,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'K14',
            'side' => 'TR-R',
        ]);

        $response->assertJsonValidationErrors(['planned_time']);
    }

    /** @test */
    public function it_validates_completion_time_logic()
    {
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '10:00',
            'status' => DailyWork::STATUS_COMPLETED,
            'inspection_result' => DailyWork::INSPECTION_PASS,
            'type' => DailyWork::TYPE_STRUCTURE,
            'description' => 'Test description with enough characters to pass validation',
            'location' => 'K14',
            'side' => 'TR-R',
            'completion_time' => '09:00', // Before planned time
        ]);

        $response->assertJsonValidationErrors(['completion_time']);
    }

    /** @test */
    public function it_validates_description_length()
    {
        // Too short
        $response = $this->postJson('/add-daily-work', [
            'date' => now()->format('Y-m-d'),
            'number' => 'TEST-' . uniqid(),
            'planned_time' => '08:00',
            'status' => DailyWork::STATUS_NEW,
            'type' => DailyWork::TYPE_STRUCTURE,
            'description' => 'Short', // Less than 10 characters
            'location' => 'K14',
            'side' => 'TR-R',
        ]);

        $response->assertJsonValidationErrors(['description']);
    }
}