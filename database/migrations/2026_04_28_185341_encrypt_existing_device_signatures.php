<?php

use App\Models\UserDevice;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Encrypt existing signature_hash values
        DB::table('user_devices')
            ->whereNotNull('signature_hash')
            ->where('signature_hash', '!=', '')
            ->get()
            ->each(function ($device) {
                try {
                    // Check if already encrypted (encrypted strings start with a specific pattern)
                    $isEncrypted = str_starts_with($device->signature_hash, 'eyJ') || 
                                   str_starts_with($device->signature_hash, 'base64:');
                    
                    if (! $isEncrypted) {
                        DB::table('user_devices')
                            ->where('id', $device->id)
                            ->update([
                                'signature_hash' => Crypt::encryptString($device->signature_hash),
                            ]);
                    }
                } catch (\Exception $e) {
                    // Log error but continue with other records
                    \Log::warning('Failed to encrypt device signature', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        // Encrypt existing signature_payload values
        DB::table('user_devices')
            ->whereNotNull('signature_payload')
            ->where('signature_payload', '!=', '')
            ->get()
            ->each(function ($device) {
                try {
                    $payload = is_string($device->signature_payload) 
                        ? $device->signature_payload 
                        : json_encode($device->signature_payload);
                    
                    $isEncrypted = str_starts_with($payload, 'eyJ') || 
                                   str_starts_with($payload, 'base64:');
                    
                    if (! $isEncrypted) {
                        DB::table('user_devices')
                            ->where('id', $device->id)
                            ->update([
                                'signature_payload' => Crypt::encrypt($payload),
                            ]);
                    }
                } catch (\Exception $e) {
                    \Log::warning('Failed to encrypt device signature payload', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // Decrypt signature_hash values
        DB::table('user_devices')
            ->whereNotNull('signature_hash')
            ->where('signature_hash', '!=', '')
            ->get()
            ->each(function ($device) {
                try {
                    $decrypted = Crypt::decryptString($device->signature_hash);
                    DB::table('user_devices')
                        ->where('id', $device->id)
                        ->update([
                            'signature_hash' => $decrypted,
                        ]);
                } catch (\Exception $e) {
                    \Log::warning('Failed to decrypt device signature', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });

        // Decrypt signature_payload values
        DB::table('user_devices')
            ->whereNotNull('signature_payload')
            ->where('signature_payload', '!=', '')
            ->get()
            ->each(function ($device) {
                try {
                    $decrypted = Crypt::decrypt($device->signature_payload);
                    DB::table('user_devices')
                        ->where('id', $device->id)
                        ->update([
                            'signature_payload' => $decrypted,
                        ]);
                } catch (\Exception $e) {
                    \Log::warning('Failed to decrypt device signature payload', [
                        'device_id' => $device->id,
                        'error' => $e->getMessage(),
                    ]);
                }
            });
    }
};
