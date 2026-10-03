<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Immutable record of one change to a role or permission assignment (see AccessAudit). */
class AccessAuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $fillable = ['actor_id', 'subject_type', 'subject_id', 'action', 'before', 'after', 'reason', 'plan_hash', 'ip', 'user_agent'];

    protected $casts = ['before' => 'array', 'after' => 'array'];
}
