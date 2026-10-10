<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RequestLog extends Model
{
    protected $fillable = [
        'ip_address',
        'method',
        'url',
        'user_agent',
        'headers',
        'request_body',
        'response_status',
        'response_body',
        'user_id',
        'duration_ms',
    ];

    protected $casts = [
        'headers' => 'array',
        'request_body' => 'array',
        'response_status' => 'integer',
        'user_id' => 'string',
        'duration_ms' => 'integer',
    ];

    /**
     * created_at is stamped by the application (app timezone, like every other table), never by the column's
     * CURRENT_TIMESTAMP default: the production database runs on the server's own zone (US Eastern), which put
     * these rows 10-11 hours behind the rest of the system. There is no updated_at column.
     */
    public const UPDATED_AT = null;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id', 'employee_id');
    }

    public function scopeByUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeByIp($query, $ip)
    {
        return $query->where('ip_address', $ip);
    }

    public function scopeByDate($query, $startDate, $endDate = null)
    {
        if ($endDate) {
            return $query->whereBetween('created_at', [$startDate, $endDate]);
        }

        return $query->where('created_at', '>=', $startDate);
    }

    public function scopeByMethod($query, $method)
    {
        return $query->where('method', $method);
    }

    public function scopeByStatus($query, $status)
    {
        return $query->where('response_status', $status);
    }
}
