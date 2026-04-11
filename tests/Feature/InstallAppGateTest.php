<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\WithFaker;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class InstallAppGateTest extends TestCase
{
    use RefreshDatabase, WithFaker;

    /** @test */
    public function guest_is_redirected_to_install_app_from_root()
    {
        $response = $this->get('/');
        $response->assertRedirect('/install-app');
    }

    /** @test */
    public function authenticated_user_is_redirected_to_install_app_from_root()
    {
        $user = \App\Models\User::factory()->create();
        $response = $this->actingAs($user)->get('/');
        $response->assertRedirect('/install-app');
    }

    /** @test */
    public function install_app_page_is_accessible()
    {
        $response = $this->get('/install-app');
        $response->assertStatus(200);
        // For Inertia SSR, check for the component name in the response
        $response->assertSee('InstallApp', false);
    }

    /** @test */
    public function apk_download_endpoint_serves_file_if_exists()
    {
        // Fake the storage and create a dummy APK file
        Storage::fake('public');
        $apkPath = storage_path('app/public/apk/latest.apk');
        if (! is_dir(dirname($apkPath))) {
            mkdir(dirname($apkPath), 0777, true);
        }
        file_put_contents($apkPath, 'dummy-apk-content');

        $response = $this->get('/apk/latest.apk');
        $response->assertOk();
        $response->assertHeader('Content-Type', 'application/vnd.android.package-archive');
        $response->assertHeader('Content-Disposition');
        $this->assertStringContainsString('dbedc-mobile-app-latest.apk', $response->headers->get('Content-Disposition'));
        unlink($apkPath);
    }

    /** @test */
    public function apk_download_endpoint_returns_404_if_missing()
    {
        $apkPath = storage_path('app/public/apk/latest.apk');
        if (file_exists($apkPath)) {
            unlink($apkPath);
        }
        $response = $this->get('/apk/latest.apk');
        $response->assertStatus(404);
    }
}
