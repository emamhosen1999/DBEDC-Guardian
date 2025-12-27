<?php
require 'vendor/autoload.php';
$app = require_once 'bootstrap/app.php';
$app->make('Illuminate\Contracts\Console\Kernel')->bootstrap();

use App\Models\DailyWork;
use App\Traits\ChainageMatcher;

class Checker {
    use ChainageMatcher;
}

$checker = new Checker();

// The objection chainages we're testing
$objectionChainages = [
    'K23+066', 'K23+073', 'K23+132', 'K23+160', 'K23+171',
    'K23+193', 'K23+232', 'K23+268', 'K23+339', 'K23+370'
];

$objectionMeters = array_map(fn($ch) => $checker->parseChainageToMeters($ch), $objectionChainages);

echo "=== Objection Chainages ===\n";
foreach ($objectionChainages as $i => $ch) {
    echo "$ch => {$objectionMeters[$i]} meters\n";
}

echo "\n=== Finding Matching RFIs ===\n";

// Get RFIs with K23 locations
$rfis = DailyWork::whereNotNull('location')
    ->where('location', 'LIKE', 'K23%')
    ->orderBy('location')
    ->get();

echo "Found " . $rfis->count() . " RFIs with K23 locations\n\n";

$matchingRfis = [];

foreach ($rfis as $rfi) {
    $rfiLocation = $rfi->location;
    $parsed = $checker->parseLocationToMeters($rfiLocation);
    
    // Check if any objection chainage matches this RFI
    $matched = false;
    $matchedChainage = null;
    
    foreach ($objectionMeters as $idx => $meters) {
        if ($parsed['is_range']) {
            // RFI is a range - check if objection point is in range
            if ($checker->isPointInRange($meters, $parsed['start'], $parsed['end'])) {
                $matched = true;
                $matchedChainage = $objectionChainages[$idx];
                break;
            }
        } else {
            // RFI is single point - check exact match
            if ($meters === $parsed['start']) {
                $matched = true;
                $matchedChainage = $objectionChainages[$idx];
                break;
            }
        }
    }
    
    if ($matched) {
        $matchingRfis[] = [
            'id' => $rfi->id,
            'number' => $rfi->number,
            'location' => $rfiLocation,
            'matched_chainage' => $matchedChainage,
            'parsed' => $parsed,
        ];
    }
}

echo "=== Matching RFIs (" . count($matchingRfis) . " found) ===\n\n";

foreach ($matchingRfis as $m) {
    $rangeInfo = $m['parsed']['is_range'] 
        ? "(range: {$m['parsed']['start']} - {$m['parsed']['end']})" 
        : "(point: {$m['parsed']['start']})";
    echo "RFI #{$m['number']} - Location: {$m['location']} $rangeInfo\n";
    echo "  Matched by objection chainage: {$m['matched_chainage']}\n\n";
}
