<?php

use App\Support\Attendance\AttendanceSymbol;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Map every leave type to its QCD register symbol and add Funeral and Maternity.
 *
 *   Casual § (Personal) | Earned △ (Annual) | Sick × | Marital □ | Funeral ■ | Maternity ◎
 *
 * Entitlements for the two new types are defaults the owner confirms:
 *   Maternity 112 days (Bangladesh Labour Act: 16 weeks), Funeral 3 days (company default).
 * Reversible: down() restores the previous symbols (Earned had a duplicate §, Marital had none)
 * and removes the two new types when nothing references them.
 */
return new class extends Migration
{
    private const PREVIOUS = ['Earned' => '§', 'Marital' => ''];

    public function up(): void
    {
        // A fresh database has no leave types yet (they are configured per installation): nothing to map or add.
        if (DB::table('leave_settings')->count() === 0) {
            return;
        }

        foreach (['Casual', 'Earned', 'Sick', 'Marital'] as $type) {
            DB::table('leave_settings')->where('type', $type)->update([
                'symbol' => AttendanceSymbol::LEAVE_TYPE_SYMBOLS[$type],
                'updated_at' => now(),
            ]);
        }

        // New types copy the conventions of the existing annual-upfront types (e.g. Marital).
        $template = (array) (DB::table('leave_settings')->where('type', 'Marital')->first()
            ?? DB::table('leave_settings')->orderBy('id')->first());
        unset($template['id']);

        $new = [
            'Funeral' => ['days' => 3, 'accrual_rate' => 3, 'eligible_gender' => null, 'eligibility' => 'Bereavement of an immediate family member'],
            'Maternity' => ['days' => 112, 'accrual_rate' => 112, 'eligible_gender' => 'female', 'eligibility' => 'Female employees (16 weeks, Bangladesh Labour Act)'],
        ];
        foreach ($new as $type => $attrs) {
            if (DB::table('leave_settings')->where('type', $type)->exists()) {
                DB::table('leave_settings')->where('type', $type)->update(['symbol' => AttendanceSymbol::LEAVE_TYPE_SYMBOLS[$type]]);

                continue;
            }
            $row = array_merge($template, $attrs, [
                'type' => $type,
                'symbol' => AttendanceSymbol::LEAVE_TYPE_SYMBOLS[$type],
                'accrual_method' => 'annual_upfront',
                'carry_forward' => 0,
                'earned_leave' => 0,
                'is_earned' => 0,
                'is_paid' => 1,
                'requires_approval' => 1,
                'auto_approve' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
            DB::table('leave_settings')->insert(array_intersect_key($row, array_flip(
                Schema::getColumnListing('leave_settings')
            )));
        }
    }

    public function down(): void
    {
        foreach (['Funeral', 'Maternity'] as $type) {
            $id = DB::table('leave_settings')->where('type', $type)->value('id');
            if ($id && ! DB::table('leaves')->where('leave_type', $id)->exists()) {
                DB::table('leave_settings')->where('id', $id)->delete();
            }
        }
        foreach (self::PREVIOUS as $type => $symbol) {
            DB::table('leave_settings')->where('type', $type)->update(['symbol' => $symbol]);
        }
    }
};
