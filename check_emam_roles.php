<?php
use App\Models\User;
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$user = User::where('email', 'emamhosen1999@gmail.com')->orWhere('id', 18)->first();
if ($user) {
    echo "User: " . $user->name . "\n";
    echo "Roles: " . implode(', ', $user->roles->pluck('name')->toArray()) . "\n";
} else {
    echo "User not found.\n";
}
