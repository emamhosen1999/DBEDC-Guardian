<?php
require __DIR__ . '/../../vendor/autoload.php';
$app = require_once __DIR__ . '/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

$users = App\Models\User::where('report_to', 18)->get();
echo "--- REPORT TO 18 ---\n";
foreach ($users as $user) {
    echo "ID: {$user->id}, Name: {$user->name}, Status: {$user->status}\n";
    
    // Check attendance for today (Jul 14)
    $hasAttendance = App\Models\HRM\Attendance::where('user_id', $user->id)
        ->whereDate('date', '2026-07-14')
        ->first();
    echo "  Today's Attendance: " . ($hasAttendance ? "PunchIn: {$hasAttendance->punchin}, Symbol: {$hasAttendance->symbol}" : "None") . "\n";

    // Resolve schedule for today and tomorrow
    $scheduleResolver = app(App\Services\Attendance\Contracts\ScheduleResolver::class);
    $schedToday = $scheduleResolver->resolve($user->id, Carbon\Carbon::parse('2026-07-14'));
    $schedTomorrow = $scheduleResolver->resolve($user->id, Carbon\Carbon::parse('2026-07-15'));
    
    echo "  Today (Jul 14) Schedule: Working: " . ($schedToday->isWorkingDay ? "Yes" : "No") . ", Start: {$schedToday->start}, End: {$schedToday->end}\n";
    echo "  Tomorrow (Jul 15) Schedule: Working: " . ($schedTomorrow->isWorkingDay ? "Yes" : "No") . ", Start: {$schedTomorrow->start}, End: {$schedTomorrow->end}\n";

    // Check if tomorrow starts within 24 hours of now (say, 19:16)
    $now = Carbon\Carbon::parse('2026-07-14 19:16:00');
    $isInWindow = $schedTomorrow->isWorkingDay && $schedTomorrow->start->gte($now) && $schedTomorrow->start->lte($now->copy()->addHours(24));
    echo "  Tomorrow in 24h window (from 19:16): " . ($isInWindow ? "Yes" : "No") . "\n";
}
