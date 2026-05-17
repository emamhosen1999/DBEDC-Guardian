<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

foreach (\DB::select('SHOW INDEX FROM users') as $r) {
    echo $r->Key_name . ' | ' . $r->Column_name . ' | ' . $r->Non_unique . "\n";
}
