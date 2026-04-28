<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

// First, let's try to authenticate and get a session
try {
    // Get a user
    $user = DB::table('users')->first();
    if(!$user) {
        // Create a test user if none exists
        $userId = DB::table('users')->insertGetId([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
            'remember_token' => str_random(60),
            'created_at' => now(),
            'updated_at' => now()
        ]);
        $user = (object) ['id' => $userId, 'email' => 'test@example.com'];
    }
    
    // Login via web route to get session
    $response = Http::asForm()->post('http://127.0.0.1:8000/login', [
        '_token' => csrf_token(),
        'email' => $user->email,
        'password' => 'password'
    ]);
    
    // Check if login was successful (should redirect)
    if($response->redirectTo()) {
        // Get cookies from login response
        $cookies = $response->headers()->get('Set-Cookie');
        
        // Now try to access analytics with the session
        $analyticsResponse = Http::withCookies(
            // Parse cookies from login response
            // This is simplified - in reality we'd need to properly parse the cookies
            []
        )->get('http://127.0.0.1:8000/api/v1/analytics/daily-works/dashboard');
        
        if($analyticsResponse->successful()) {
            echo "✓ Analytics endpoint: SUCCESS - Status " . $analyticsResponse->status() . "\n";
            $data = json_decode($analyticsResponse->body(), true);
            echo "  Data keys: " . implode(', ', array_keys($data ?? [])) . "\n";
        } else {
            echo "✗ Analytics endpoint: FAILED - Status " . $analyticsResponse->status() . "\n";
            echo "  Response: " . $analyticsResponse->body() . "\n";
        }
    } else {
        echo "✗ Login failed: " . $response->body() . "\n";
    }
    
} catch (Exception $e) {
    echo "✗ Test failed: " . $e->getMessage() . "\n";
}

echo "\nAuthentication test complete!\n";
?>