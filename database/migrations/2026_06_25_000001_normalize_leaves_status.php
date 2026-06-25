<?php
// database/migrations/2026_06_25_000001_normalize_leaves_status.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Canonicalize leaves.status to {pending, approved, rejected, cancelled}.
     * Idempotent: safe to re-run. Case-insensitive mapping of all known legacy values.
     */
    public function up(): void
    {
        if (! Schema::hasTable('leaves')) {
            return;
        }

        $map = [
            'new' => 'pending',
            'pending' => 'pending',
            'approved' => 'approved',
            'declined' => 'rejected',
            'rejected' => 'rejected',
            'cancelled' => 'cancelled',
            'canceled' => 'cancelled',
        ];

        // Pull distinct existing values, map case-insensitively, write canonical.
        $existing = DB::table('leaves')->select('status')->distinct()->pluck('status');
        foreach ($existing as $value) {
            $canonical = $map[strtolower((string) $value)] ?? 'pending';
            if ((string) $value !== $canonical) {
                DB::table('leaves')->where('status', $value)->update(['status' => $canonical]);
            }
        }
    }

    public function down(): void
    {
        // Non-destructive: legacy casing is not restored.
    }
};
