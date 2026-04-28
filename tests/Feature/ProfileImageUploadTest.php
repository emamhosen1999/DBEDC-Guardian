<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ProfileImageUploadTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $employee;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles
        $this->artisan('db:seed', ['--class' => 'ComprehensiveRolePermissionSeeder']);

        // Create admin user
        $this->admin = User::factory()->create();
        $this->admin->assignRole('Super Administratoristrator');

        // Create employee user
        $this->employee = User::factory()->create();
        $this->employee->assignRole('Member');
    }

    /** @test */
    public function user_can_upload_own_profile_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->employee);

        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 500, 500),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Profile image uploaded successfully',
        ]);

        // Verify the media was attached
        $this->employee->refresh();
        $this->assertTrue($this->employee->hasMedia('profile_images'));
        $this->assertNotNull($response->json('profile_image_url'));
    }

    /** @test */
    public function admin_can_upload_profile_image_for_any_user(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin);

        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('profile.png', 500, 500),
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
            'message' => 'Profile image uploaded successfully',
        ]);

        // Verify the media was attached to the employee
        $this->employee->refresh();
        $this->assertTrue($this->employee->hasMedia('profile_images'));
    }

    /** @test */
    public function profile_image_replaces_existing_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->employee);

        // Upload first image
        $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('first.jpg', 500, 500),
        ]);

        // Upload second image
        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('second.jpg', 500, 500),
        ]);

        $response->assertStatus(200);

        // Should only have one media item (the second one replaced the first)
        $this->employee->refresh();
        $this->assertCount(1, $this->employee->getMedia('profile_images'));
    }

    /** @test */
    public function user_can_remove_own_profile_image(): void
    {
        Storage::fake('public');

        $this->actingAs($this->employee);

        // First upload an image
        $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 500, 500),
        ]);

        // Verify it was uploaded
        $this->employee->refresh();
        $this->assertTrue($this->employee->hasMedia('profile_images'));

        // Now remove it
        $response = $this->deleteJson(route('profile.image.remove'), [
            'user_id' => $this->employee->id,
        ]);

        $response->assertStatus(200);
        $response->assertJson([
            'success' => true,
        ]);

        // Verify it was removed
        $this->employee->refresh();
        $this->assertFalse($this->employee->hasMedia('profile_images'));
    }

    /** @test */
    public function upload_validates_image_dimensions(): void
    {
        Storage::fake('public');

        $this->actingAs($this->employee);

        // Try to upload image that's too small
        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('small.jpg', 50, 50),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['profile_image']]);
    }

    /** @test */
    public function upload_validates_file_type(): void
    {
        Storage::fake('public');

        $this->actingAs($this->employee);

        // Try to upload a non-image file
        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->create('document.pdf', 100),
        ]);

        $response->assertStatus(422);
        $response->assertJsonStructure(['errors' => ['profile_image']]);
    }

    /** @test */
    public function user_cannot_upload_profile_image_for_other_user(): void
    {
        Storage::fake('public');

        $otherMember = User::factory()->create();
        $otherMember->assignRole('Member');

        $this->actingAs($this->employee);

        $response = $this->postJson(route('profile.image.upload'), [
            'user_id' => $otherMember->id,
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 500, 500),
        ]);

        $response->assertStatus(403);
    }

    /** @test */
    public function profile_image_url_accessor_returns_media_url(): void
    {
        Storage::fake('public');

        $this->actingAs($this->admin);

        // Upload an image
        $this->postJson(route('profile.image.upload'), [
            'user_id' => $this->employee->id,
            'profile_image' => UploadedFile::fake()->image('profile.jpg', 500, 500),
        ]);

        $this->employee->refresh();

        // The accessor should return the media URL
        $profileImageUrl = $this->employee->profile_image_url;

        $this->assertNotNull($profileImageUrl);
        $this->assertStringContainsString('storage', $profileImageUrl);
    }
}
