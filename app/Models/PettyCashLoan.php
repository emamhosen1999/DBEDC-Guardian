<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PettyCashLoan extends Model
{
    protected $fillable = [
        'user_id',
        'fund_name',
        'loan_amount',
        'original_amount',
        'current_balance',
        'status',
        'loan_date',
        'closed_date',
        'notes',
        'approved_by',
        'approval_comment',
        'approved_at',
        'rejected_at',
    ];

    protected $casts = [
        'loan_amount' => 'decimal:2',
        'original_amount' => 'decimal:2',
        'current_balance' => 'decimal:2',
        'loan_date' => 'date',
        'closed_date' => 'date',
        'approved_at' => 'datetime',
        'rejected_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(PettyCashTransaction::class);
    }

    public function auditLogs(): HasMany
    {
        return $this->hasMany(PettyCashAuditLog::class);
    }

    public function calculateBalance(): string
    {
        $balance = (float) $this->original_amount;

        foreach ($this->transactions as $transaction) {
            if ($transaction->type === 'expense' || $transaction->type === 'repayment') {
                $balance -= (float) $transaction->amount;
            } elseif ($transaction->type === 'reimbursement') {
                $balance += (float) $transaction->amount;
            }
        }

        return number_format($balance, 2, '.', '');
    }

    public function updateBalance(): void
    {
        $this->current_balance = (string) $this->calculateBalance();
        $this->save();
    }

    public function scopeForUser($query, $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeActive($query)
    {
        return $query->where('status', 'active');
    }
}
