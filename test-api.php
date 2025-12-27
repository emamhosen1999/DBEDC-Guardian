<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

// Simulate the API call
$obj = \App\Models\RfiObjection::with('chainages')->find(4);
$summary = $obj->chainage_summary ?? [];
$specific = $summary['specific'] ?? [];

echo "Objection ID 4 specific chainages count: " . count($specific) . "\n";

if (count($specific) > 0) {
    $chainagesStr = implode(', ', $specific);
    echo "First 100 chars: " . substr($chainagesStr, 0, 100) . "...\n";
    echo "Total length: " . strlen($chainagesStr) . "\n";
    
    // Test the controller
    $controller = new \App\Http\Controllers\ObjectionController();
    $request = \Illuminate\Http\Request::create('/suggest-rfis', 'GET', [
        'chainage_from' => $chainagesStr,
    ]);
    
    echo "\nCalling suggestRfis controller...\n";
    
    try {
        $response = $controller->suggestRfis($request);
        $data = json_decode($response->getContent(), true);
        
        echo "Status: " . $response->getStatusCode() . "\n";
        echo "Count: " . ($data['count'] ?? 0) . "\n";
        echo "Match type: " . ($data['match_type'] ?? 'unknown') . "\n";
        
        if (!empty($data['error'])) {
            echo "Error: " . $data['error'] . "\n";
            echo "Message: " . ($data['message'] ?? '') . "\n";
        }
        
        if (!empty($data['rfis']) && count($data['rfis']) > 0) {
            echo "\nFirst 5 RFIs:\n";
            foreach (array_slice($data['rfis'], 0, 5) as $rfi) {
                echo "  - {$rfi['number']}: {$rfi['location']}\n";
            }
        }
    } catch (\Exception $e) {
        echo "Exception: " . $e->getMessage() . "\n";
        echo "File: " . $e->getFile() . ":" . $e->getLine() . "\n";
    }
}
