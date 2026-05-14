<?php

namespace App\Models\HRM;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class BiometricDeviceCommand extends Model
{
    use HasFactory;

    protected $fillable = [
        'biometric_device_id',
        'command_type',
        'payload',
        'status',
        'retry_count',
        'return_code',
        'error_message',
        'sent_at',
        'executed_at',
    ];

    protected $casts = [
        'payload' => 'array',
        'sent_at' => 'datetime',
        'executed_at' => 'datetime',
    ];

    /**
     * Relationship with the device
     */
    public function device()
    {
        return $this->belongsTo(BiometricDevice::class, 'biometric_device_id');
    }

    /**
     * Convert command to ADMS protocol string format
     * Format: C:ID:COMMAND
     */
    public function toAdmsString(): string
    {
        $command = "C:{$this->id}:";
        $payload = $this->payload ?? [];

        switch ($this->command_type) {
            case 'REBOOT':
                $command .= "REBOOT";
                break;

            case 'SET_TIME':
                $command .= "SET TIME " . ($payload['time'] ?? now()->format('Y-m-d H:i:s'));
                break;

            case 'ADD_USER':
            case 'UPDATE_USER':
                $command .= "DATA UPDATE USERINFO";
                $command .= " PIN=" . ($payload['pin'] ?? '');
                $command .= " Name=" . ($payload['name'] ?? '');
                if (isset($payload['card'])) {
                    $command .= " Card=" . $payload['card'];
                }
                if (isset($payload['privilege'])) {
                    $command .= " Pri=" . $payload['privilege'];
                }
                break;

            case 'DELETE_USER':
                $command .= "DATA DELETE USERINFO PIN=" . ($payload['pin'] ?? '');
                break;

            case 'CLEAR_LOG':
                $command .= "CLEAR LOG";
                break;

            case 'CLEAR_DATA':
                $command .= "CLEAR DATA";
                break;

            case 'GET_USERINFO':
                $command .= "GET USERINFO";
                break;

            default:
                $command .= "UNKNOWN";
                break;
        }

        return $command;
    }

    /**
     * Mark command as sent
     */
    public function markAsSent(): void
    {
        $this->update([
            'status' => 'sent',
            'sent_at' => now(),
            'retry_count' => $this->retry_count + 1,
        ]);
    }

    /**
     * Mark command as executed
     */
    public function markAsExecuted(string $returnCode = '0'): void
    {
        $this->update([
            'status' => $returnCode == '0' ? 'executed' : 'failed',
            'return_code' => $returnCode,
            'executed_at' => now(),
        ]);
    }

    /**
     * Mark command as failed
     */
    public function markAsFailed(string $errorMessage): void
    {
        $this->update([
            'status' => 'failed',
            'error_message' => $errorMessage,
            'executed_at' => now(),
        ]);
    }

    /**
     * Scope for pending commands
     */
    public function scopePending($query)
    {
        return $query->where('status', 'pending');
    }

    /**
     * Scope for a specific device
     */
    public function scopeForDevice($query, $deviceId)
    {
        return $query->where('biometric_device_id', $deviceId);
    }

    /**
     * Scope for oldest commands first
     */
    public function scopeOldest($query)
    {
        return $query->orderBy('created_at', 'asc');
    }
}
