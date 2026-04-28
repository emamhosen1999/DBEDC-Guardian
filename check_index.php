<?php
require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);

$indexes = DB::select('SHOW INDEX FROM daily_works');

foreach($indexes as $index) {
    if($index->Key_name == 'idx_daily_works_search') {
        echo "FULLTEXT index found: " . $index->Index_type . PHP_EOL;
        exit(0);
    }
}

echo "FULLTEXT index NOT found" . PHP_EOL;
exit(1);
?>