<?php

namespace App\Models\HRM;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

class BiometricDownloadSession extends Model
{
    protected $fillable = [
        'biometric_device_id',
        'trigger_type',
        'status',
        'total_records',
        'processed_count',
        'duplicate_count',
        'failed_count',
        'error_message',
        'command_id',
        'started_at',
        'completed_at',
        'created_by',
    ];

    protected $casts = [
        'started_at' => 'datetime',
        'completed_at' => 'datetime',
    ];

    public function device()
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    public function command()
    {
        return $this->belongsTo(BiometricDeviceCommand::class, 'command_id');
    }

    public function creator()
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function markInProgress(): void
    {
        $this->update([
            'status' => 'in_progress',
            'started_at' => now(),
        ]);
    }

    public function markCompleted(array $stats = []): void
    {
        $this->update([
            'status' => 'completed',
            'total_records' => $stats['total_records'] ?? $this->total_records,
            'processed_count' => $stats['processed_count'] ?? $this->processed_count,
            'duplicate_count' => $stats['duplicate_count'] ?? $this->duplicate_count,
            'failed_count' => $stats['failed_count'] ?? $this->failed_count,
            'completed_at' => now(),
        ]);
    }

    public function markFailed(string $message): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $message,
            'completed_at' => now(),
        ]);
    }

    public function markPartial(array $stats = []): void
    {
        $this->update([
            'status' => 'partial',
            'total_records' => $stats['total_records'] ?? $this->total_records,
            'processed_count' => $stats['processed_count'] ?? $this->processed_count,
            'duplicate_count' => $stats['duplicate_count'] ?? $this->duplicate_count,
            'failed_count' => $stats['failed_count'] ?? $this->failed_count,
            'completed_at' => now(),
        ]);
    }

    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('biometric_device_id', $deviceId);
    }

    public function scopeRecent($query, $days = 7)
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }
}
