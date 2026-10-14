<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** One explicit, audited move of open works to the incoming in-charge. */
class JurisdictionHandoverTransfer extends Model
{
    protected $fillable = ['handover_id', 'to_employee_id', 'works_count', 'work_ids', 'previous_incharge', 'performed_by', 'performed_at'];

    protected $casts = ['work_ids' => 'array', 'previous_incharge' => 'array', 'performed_at' => 'datetime'];
}
