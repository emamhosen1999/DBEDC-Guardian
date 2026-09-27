<?php

namespace App\Services\Attendance;

use App\Models\HRM\AbsenceCase;
use App\Models\User;
use Carbon\Carbon;

class AbsenceNoticeService
{
    /**
     * Generate Return-to-Work Notice under Bangladesh Labour Act 2006 s.27(3A).
     */
    public function generateReturnToWorkNotice(AbsenceCase $case): array
    {
        $employee = $case->employee ?? User::where('employee_id', $case->user_id)->first();
        $employeeName = $employee?->name ?? $case->user_id;
        $employeeId = $case->user_id;
        $dept = $employee?->department?->name ?? 'Operations';
        $designation = $employee?->designation?->title ?? $employee?->designation?->name ?? 'Employee';
        $firstAbsent = $case->first_absent_date ? $case->first_absent_date->format('F d, Y') : 'N/A';
        $today = now()->format('F d, Y');
        $streak = $case->streak_days;

        $subject = "NOTICE TO RESUME DUTY AND EXPLAIN UNAUTHORIZED ABSENCE";

        $body = <<<TEXT
Date: {$today}

To:
{$employeeName} (Employee ID: {$employeeId})
Designation: {$designation}
Department: {$dept}
Dhaka Bypass Expressway Development Company Limited (DBEDC)

Subject: {$subject}

Dear {$employeeName},

It has been observed from official attendance records that you have remained absent from your rostered duties without any prior permission or sanctioned leave since {$firstAbsent}, accumulating a consecutive absence of {$streak} working day(s) up to {$today}.

Under the operational rules of DBEDC and Section 27(3A) of the Bangladesh Labour Act, 2006 (as amended), continuous absence without permission constitutes unauthorized absence.

You are hereby advised to:
1. Immediately resume your official rostered duties; and
2. Submit a written explanation stating the genuine reasons for your unauthorized absence along with supporting documentation (e.g. medical records, if applicable) to the Human Resources Department within seven (7) days of receipt of this notice.

Please take notice that failure to resume duties or provide a satisfactory written explanation within the stipulated timeline will lead to formal disciplinary proceedings, and your employment may be treated as deemed resignation due to abandonment under the prevailing law.

Issued on behalf of DBEDC Management,

Human Resources Department
Dhaka Bypass Expressway Development Company Limited
TEXT;

        return [
            'type' => 'return_to_work',
            'subject' => $subject,
            'body' => $body,
            'employee_name' => $employeeName,
            'employee_id' => $employeeId,
            'date' => $today,
        ];
    }

    /**
     * Generate Show-Cause Notice under Bangladesh Labour Act 2006 s.27(3A).
     */
    public function generateShowCauseNotice(AbsenceCase $case): array
    {
        $employee = $case->employee ?? User::where('employee_id', $case->user_id)->first();
        $employeeName = $employee?->name ?? $case->user_id;
        $employeeId = $case->user_id;
        $dept = $employee?->department?->name ?? 'Operations';
        $designation = $employee?->designation?->title ?? $employee?->designation?->name ?? 'Employee';
        $firstAbsent = $case->first_absent_date ? $case->first_absent_date->format('F d, Y') : 'N/A';
        $today = now()->format('F d, Y');
        $streak = $case->streak_days;

        $subject = "FINAL SHOW-CAUSE NOTICE FOR PROLONGED UNAUTHORIZED ABSENCE (ABSCONDING)";

        $body = <<<TEXT
Date: {$today}

To:
{$employeeName} (Employee ID: {$employeeId})
Designation: {$designation}
Department: {$dept}
Dhaka Bypass Expressway Development Company Limited (DBEDC)

Subject: {$subject}

Dear {$employeeName},

Reference is made to your continuous and unauthorized absence from official duties commencing on {$firstAbsent}. Despite prior operational reminders and warnings, you have failed to report for work or communicate with your reporting supervisor or HR Department, resulting in {$streak} consecutive absent working days.

Such prolonged absence without official authorization severely impacts continuous traffic operations and monitoring, and constitutes gross misconduct and job abandonment under Section 27(3A) and Section 23 of the Bangladesh Labour Act, 2006.

You are hereby called upon to SHOW CAUSE in writing within seven (7) working days from the date of this notice as to why disciplinary action, including termination of employment on grounds of absconding/job abandonment and forfeiture of notice pay, should not be initiated against you.

If no written explanation is received within the specified deadline, it will be conclusively presumed that you have abandoned your employment voluntarily with no intention to resume, and management will proceed with formal offboarding and revocation of all corporate and physical access.

Issued by order of Management,

Head of Human Resources & Administration
Dhaka Bypass Expressway Development Company Limited
TEXT;

        return [
            'type' => 'show_cause',
            'subject' => $subject,
            'body' => $body,
            'employee_name' => $employeeName,
            'employee_id' => $employeeId,
            'date' => $today,
        ];
    }
}
