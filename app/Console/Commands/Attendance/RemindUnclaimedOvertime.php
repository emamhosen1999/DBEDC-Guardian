<?php

namespace App\Console\Commands\Attendance;

use App\Models\HRM\Attendance;
use App\Models\User;
use App\Notifications\Attendance\OvertimeUnclaimedReminderNotification;
use App\Services\Attendance\OvertimeEligibilityService;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Next morning: tell each employee who worked a rest day or holiday yesterday without claiming it. One reminder per
 * employee per day; only days still inside the claim window.
 */
class RemindUnclaimedOvertime extends Command
{
    protected $signature = 'overtime:remind-unclaimed {--date= : The work date to remind about (default yesterday)}';

    protected $description = 'Remind employees of rest-day / holiday work that has not been claimed';

    public function handle(OvertimeEligibilityService $eligibility): int
    {
        if (! config('overtime_claims.unclaimed_reminder_enabled')) {
            return self::SUCCESS;
        }
        $date = $this->option('date') ? Carbon::parse($this->option('date')) : Carbon::yesterday();
        $ids = Attendance::query()->whereDate('date', $date->toDateString())->whereNotNull('punchin')->distinct()->pluck('user_id');
        $sent = 0;
        foreach ($ids as $userId) {
            $row = $eligibility->evaluate((string) $userId, $date);
            if (! $row['eligible'] || $row['claimed']) {
                continue;
            }
            $user = User::find($userId);
            $already = DB::table('overtime_claim_reminders')->where('user_id', (string) $userId)->where('work_date', $date->toDateString())->exists();
            if (! $user || $already) {
                continue;
            }
            $user->notify(new OvertimeUnclaimedReminderNotification($date->toDateString(), (float) $row['credit'], (bool) $row['punch_out_missing']));
            DB::table('overtime_claim_reminders')->insert(['user_id' => (string) $userId, 'work_date' => $date->toDateString(), 'created_at' => now()]);
            $sent++;
        }
        $this->info("{$sent} reminder(s) for {$date->toDateString()}.");

        return self::SUCCESS;
    }
}
