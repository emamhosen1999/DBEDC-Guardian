<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One period of an employee reporting to one manager (effective-dated; see ReportingLineService). */
class EmployeeReportingLine extends Model
{
    protected $fillable = ['employee_id', 'reports_to', 'valid_from', 'valid_to', 'changed_by'];

    protected $casts = ['valid_from' => 'date:Y-m-d', 'valid_to' => 'date:Y-m-d'];

    protected static function booted(): void
    {
        static::saving(function (EmployeeReportingLine $l): void {
            $l->open_for = $l->valid_to === null ? $l->employee_id : null;
        });
    }
}
