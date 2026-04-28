<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class EnsureSuperAdmin extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'users:ensure-superadmin {email?} {--name=Admin} {--username=admin} {--password=admin123}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Ensure a Super Administratoristrator user exists and has the proper role';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email') ?? 'admin@example.com';
        $name = $this->option('name');
        $username = $this->option('username');
        $password = $this->option('password');

        // Check if the Super Administratoristrator role exists
        $role = Role::where('name', 'Super Administratoristrator')->first();
        if (! $role) {
            $this->error('Super Administratoristrator role not found! Please run the permission seeder first.');

            return 1;
        }

        // Check if the user exists
        $user = User::where('email', $email)->first();

        if ($user) {
            $this->info("User {$email} already exists. Ensuring proper role assignment...");

            // Ensure the user has the Super Administratoristrator role
            if (! $user->hasRole('Super Administratoristrator')) {
                // Remove any existing roles
                DB::table('model_has_roles')->where('model_id', $user->id)
                    ->where('model_type', User::class)
                    ->delete();

                // Assign the Super Administratoristrator role
                $user->assignRole('Super Administratoristrator');
                $this->info("Super Administratoristrator role assigned to {$email}");
            } else {
                $this->info('User already has Super Administratoristrator role.');
            }
        } else {
            // Create a new user with the Super Administratoristrator role
            $user = User::create([
                'name' => $name,
                'user_name' => $username,
                'email' => $email,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
                'status' => 'active',
            ]);

            $user->assignRole('Super Administratoristrator');

            $this->info("Super Administratoristrator user created with email: {$email} and password: {$password}");
        }

        $this->info('✅ Super Administratoristrator user is ready to use.');

        return 0;
    }
}
