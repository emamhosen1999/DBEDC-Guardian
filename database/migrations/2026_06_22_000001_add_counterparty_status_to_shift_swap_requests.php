<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two-stage shift swap: the counterparty (affected coworker) consents BEFORE the
 * manager/admin authorizes. `counterparty_status` tracks the peer stage; the existing
 * `status` column remains the admin/manager stage.
 *
 * - null      → no peer step needed (give-away / open swap with no named counterparty)
 * - pending   → awaiting the counterparty's accept/decline
 * - accepted  → counterparty agreed; now actionable by the admin (status stays 'pending')
 * - declined  → counterparty refused (terminal; status is set to 'rejected')
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->enum('counterparty_status', ['pending', 'accepted', 'declined'])
                ->nullable()
                ->after('counterparty_date');
        });
    }

    public function down(): void
    {
        Schema::table('shift_swap_requests', function (Blueprint $table) {
            $table->dropColumn('counterparty_status');
        });
    }
};
