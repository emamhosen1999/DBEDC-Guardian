<?php

return [
    'refused' => [
        'working_day' => 'Overtime applies only to work on your rest days and holidays.',
        'no_punch' => 'There is no punch on this date, so there is no work to claim.',
        'already_claimed' => 'This day has already been claimed.',
        'under_minimum' => 'At least :hours hours of work are needed to claim a day.',
        'punch_out_missing_pay' => 'Overtime pay cannot be claimed until the missing punch-out is corrected.',
        'declare_end' => 'There is no punch-out for this day. Enter the time you finished work to claim replacement leave.',
        'pay_needs_punch_out' => 'Overtime pay cannot be claimed until the missing punch-out is corrected. You can claim replacement leave on the end time you declare.',
        'holiday_pay_only' => 'Holiday work is compensated with overtime pay only.',
        'compensation_required' => 'Choose overtime pay or replacement leave.',
        'declared_end_invalid' => 'The end time must be after your punch-in on the same day.',
    ],
    'replacement' => [
        'not_working_day' => 'Replacement leave can only be taken on a day you are rostered to work.',
        'holiday' => 'That date is a holiday; choose a rostered working day.',
        'in_past' => 'Choose today or a future date.',
        'no_credit' => 'You do not have enough replacement leave for that.',
        'half_day_only' => 'A half-day credit can only be used as a half day.',
        'duplicate' => 'You already have a replacement request for that date.',
        'on_leave' => 'You are already on leave on that date.',
    ],
];
