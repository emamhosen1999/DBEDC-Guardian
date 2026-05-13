<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $exists = DB::table('attendance_types')->where('slug', 'biometric')->exists();
        if (! $exists) {
            DB::table('attendance_types')->insert([
                'name'                 => 'Biometric Device',
                'slug'                 => 'biometric',
                'description'          => 'ZKTeco biometric device attendance via push webhook.',
                'icon'                 => 'fingerprint',
                'is_active'            => true,
                'priority'             => 10,
                'config'               => json_encode([
                    'validation_mode' => 'any',
                ]),
                'required_permissions' => json_encode([]),
                'created_at'           => now(),
                'updated_at'           => now(),
            ]);
        }
    }

    public function down(): void
    {
        DB::table('attendance_types')->where('slug', 'biometric')->delete();
    }
};
