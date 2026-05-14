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
        'user_id' => 'integer',
        'duration_ms' => 'integer',
    ];

    public $timestamps = false;

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
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
