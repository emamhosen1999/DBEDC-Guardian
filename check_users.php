<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$users = User::all(['id', 'name', 'email', 'employee_id']);
echo "USERS:\n";
foreach ($users as $u) {
    echo "ID: {$u->id} | Name: {$u->name} | Email: {$u->email} | EmployeeID: {$u->employee_id}\n";
}

$roles = DB::table('roles')->get();
echo "\nROLES:\n";
foreach ($roles as $r) {
    echo "Role: {$r->name}\n";
}

$userRoles = DB::table('model_has_roles')
    ->join('roles', 'model_has_roles.role_id', '=', 'roles.id')
    ->join('users', 'model_has_roles.model_id', '=', 'users.id')
    ->select('users.email', 'roles.name')
    ->get();
echo "\nUSER ROLES:\n";
foreach ($userRoles as $ur) {
    echo "User: {$ur->email} | Role: {$ur->name}\n";
}
