<?php
require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\HRM\ShiftAssignment;
use Illuminate\Support\Facades\DB;

$assignments = [
    // Friday Off (Pattern ID 6)
    ['user_id' => 6, 'pattern_id' => 6], // A.K.M Shifur Rahaman
    ['user_id' => 3, 'pattern_id' => 6], // Debashis Jha
    ['user_id' => 8, 'pattern_id' => 6], // Fuad
    ['user_id' => 19, 'pattern_id' => 6], // Md. Emamul
    ['user_id' => 26, 'pattern_id' => 6], // Md. Fahim Hossain
    ['user_id' => 7, 'pattern_id' => 6], // Md. Munirujjaman
    ['user_id' => 25, 'pattern_id' => 6], // Md. Raisul Islam Rahat
    ['user_id' => 95, 'pattern_id' => 6], // Md. Rajibul Islam

    // Sunday Off (Pattern ID 7)
    ['user_id' => 98, 'pattern_id' => 7], // A.S.M Oli
    ['user_id' => 16, 'pattern_id' => 7], // Nymul

    // Monday Off (Pattern ID 8)
    ['user_id' => 5, 'pattern_id' => 8], // Md. Habibur
    ['user_id' => 97, 'pattern_id' => 8], // Md. Nazmul Hasan
    ['user_id' => 4, 'pattern_id' => 8], // Prodip

    // Tuesday Off (Pattern ID 9)
    ['user_id' => 13, 'pattern_id' => 9], // Md. Babar
    ['user_id' => 12, 'pattern_id' => 9], // Md. Sobuj
    ['user_id' => 9, 'pattern_id' => 9], // Md. Uzzal Mia

    // Wednesday Off (Pattern ID 10)
    ['user_id' => 14, 'pattern_id' => 10], // Md. Main Uddin
    ['user_id' => 23, 'pattern_id' => 10], // Subrata Kumar Chaki
];

$count = 0;
DB::transaction(function () use ($assignments, &$count) {
    foreach ($assignments as $a) {
        // Double check we don't duplicate
        $exists = ShiftAssignment::where('scope_type', 'user')
            ->where('scope_id', $a['user_id'])
            ->where('effective_from', '2026-06-01')
            ->exists();

        if (!$exists) {
            ShiftAssignment::create([
                'scope_type' => 'user',
                'scope_id' => $a['user_id'],
                'shift_id' => null,
                'rotation_pattern_id' => $a['pattern_id'],
                'anchor_date' => '2026-06-01',
                'effective_from' => '2026-06-01',
                'effective_to' => null,
                'priority' => 0,
                'assigned_by' => null,
            ]);
            $count++;
        }
    }
});

echo "Successfully created {$count} assignments." . PHP_EOL;
