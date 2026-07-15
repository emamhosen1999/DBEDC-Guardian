<?php

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

// Authenticate as Fahim Hossain
$user = User::where('email', 'fahim@dhakabypass.com')->firstOrFail();
Auth::login($user);

echo "==================================================\n";
echo "SIMULATING FRONTEND PAGES FOR FAHIM HOSSAIN\n";
echo "==================================================\n\n";

// 1. Get /attendance page props
echo "PAGE: /attendance\n";
$req1 = Request::create('/attendance', 'GET');
$req1->setUserResolver(fn() => $user);
$res1 = $app->handle($req1);
$status1 = $res1->getStatusCode();
$content1 = $res1->getContent();
echo "• Status Code: $status1\n";

// Extract the data-page attribute from HTML
if (preg_match('/data-page="([^"]+)"/', $content1, $matches)) {
    $pageData = json_decode(html_entity_decode($matches[1]), true);
    
    echo "• Title: " . ($pageData['props']['title'] ?? 'Attendance') . "\n";
    echo "• Auth User: " . ($pageData['props']['auth']['user']['name'] ?? 'None') . "\n";
    echo "• User Permissions Count: " . count($pageData['props']['auth']['permissions'] ?? []) . "\n";
    echo "• Active User Roles: " . implode(', ', $pageData['props']['auth']['roles'] ?? []) . "\n";
    
    // Check what departments/designations are passed as props to the frontend
    $propsDepts = $pageData['props']['departments'] ?? [];
    echo "• Departments passed to dropdown props: " . count($propsDepts) . "\n";
    foreach ($propsDepts as $d) {
        echo "  - " . $d['name'] . " (ID: " . $d['id'] . ")\n";
    }
} else {
    echo "❌ Failed to extract Inertia page data for /attendance.\n";
}
echo "\n--------------------------------------------------\n\n";

// 2. Get /employees page props
echo "PAGE: /employees\n";
$req2 = Request::create('/employees', 'GET');
$req2->setUserResolver(fn() => $user);
$res2 = $app->handle($req2);
$status2 = $res2->getStatusCode();
$content2 = $res2->getContent();
echo "• Status Code: $status2\n";

// Extract the data-page attribute from HTML
if (preg_match('/data-page="([^"]+)"/', $content2, $matches)) {
    $pageData = json_decode(html_entity_decode($matches[1]), true);
    
    echo "• Title: " . ($pageData['props']['title'] ?? 'Employees') . "\n";
    
    // Check departments passed as props to Employees page
    $propsDepts = $pageData['props']['departments'] ?? [];
    echo "• Departments passed to dropdown props: " . count($propsDepts) . "\n";
    foreach ($propsDepts as $d) {
        echo "  - " . $d['name'] . " (ID: " . $d['id'] . ")\n";
    }
    
    // Check designations passed as props
    $propsDesigs = $pageData['props']['designations'] ?? [];
    echo "• Designations passed to dropdown props: " . count($propsDesigs) . "\n";
    
    // Check total departments list in the Department Tab
    $initialDepts = $pageData['props']['departmentsData']['data'] ?? [];
    echo "• Departments loaded in Department Tab table: " . count($initialDepts) . "\n";
    foreach ($initialDepts as $d) {
        echo "  - " . $d['name'] . " (Manager: " . ($d['manager']['name'] ?? 'None') . ")\n";
    }
} else {
    echo "❌ Failed to extract Inertia page data for /employees.\n";
    echo "Response preview:\n" . substr($content2, 0, 1000) . "\n";
}
echo "==================================================\n";
