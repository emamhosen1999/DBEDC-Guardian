<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashAuditLog;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PettyCashService
{
    // ─── Available categories (Phase 2) ───
    public const CATEGORIES = [
        'fuel' => 'Fuel',
        'office_supplies' => 'Office Supplies',
        'meeting_supplies' => 'Meeting Supplies',
        'office_maintenance' => 'Office Maintenance',
        'services' => 'Services',
        'transport' => 'Transport',
        'utilities' => 'Utilities',
        'food_beverage' => 'Food & Beverage',
        'miscellaneous' => 'Miscellaneous',
    ];

    public function getCategories(): array
    {
        return self::CATEGORIES;
    }

    // ─── Loan CRUD ───

    public function createLoan(array $data): PettyCashLoan
    {
        return DB::transaction(function () use ($data) {
            $loan = PettyCashLoan::create([
                'user_id' => Auth::id(),
                'fund_name' => $data['fund_name'] ?? 'General Fund',
                'loan_amount' => $data['amount'],
                'original_amount' => $data['amount'],
                'current_balance' => 0.00,
                'status' => 'pending_approval',
                'loan_date' => $data['loan_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            $this->logAudit($loan->id, 'created', 'loan', $loan->id, null, $loan->toArray());

            return $loan;
        });
    }

    // ─── Transaction Operations ───

    public function addExpense(PettyCashLoan $loan, array $data): PettyCashTransaction
    {
        if ($loan->status !== 'active') {
            throw new \Exception('Cannot add expense to a non-active loan.');
        }

        return DB::transaction(function () use ($loan, $data) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $category = ($data['category'] ?? null);
            if ($category === 'none') $category = null;

            $transaction = $loan->transactions()->create([
                'type' => 'expense',
                'category' => $category,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            ]);

            $loan->updateBalance();
            $this->logAudit($loan->id, 'created', 'transaction', $transaction->id, null, $transaction->toArray());

            return $transaction;
        });
    }

    public function addReimbursement(PettyCashLoan $loan, array $data): PettyCashTransaction
    {
        if ($loan->status !== 'active') {
            throw new \Exception('Cannot add reimbursement to a non-active loan.');
        }

        return DB::transaction(function () use ($loan, $data) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $category = ($data['category'] ?? null);
            if ($category === 'none') $category = null;

            $transaction = $loan->transactions()->create([
                'type' => 'reimbursement',
                'category' => $category,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            ]);

            $loan->updateBalance();
            $this->logAudit($loan->id, 'created', 'transaction', $transaction->id, null, $transaction->toArray());

            return $transaction;
        });
    }

    public function addRepayment(PettyCashLoan $loan, array $data): PettyCashTransaction
    {
        if ($loan->status !== 'active') {
            throw new \Exception('Cannot add repayment to a non-active loan.');
        }

        return DB::transaction(function () use ($loan, $data) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $transaction = $loan->transactions()->create([
                'type' => 'repayment',
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            ]);

            $loan->updateBalance();

            // Check if loan is fully repaid
            if ((float) $loan->current_balance <= 0) {
                $loan->update([
                    'status' => 'settled',
                    'closed_date' => now()->toDateString(),
                ]);
            }

            $this->logAudit($loan->id, 'created', 'transaction', $transaction->id, null, $transaction->toArray());

            return $transaction;
        });
    }

    public function updateTransaction(PettyCashTransaction $transaction, array $data): PettyCashTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($transaction->petty_cash_loan_id);
            if ($loan->status !== 'active') {
                throw new \Exception('Transactions can only be updated on active loans.');
            }

            $oldValues = $transaction->toArray();
            $category = ($data['category'] ?? $transaction->category);
            if ($category === 'none') $category = null;

            $transaction->update([
                'category' => $category,
                'amount' => $data['amount'] ?? $transaction->amount,
                'description' => $data['description'] ?? $transaction->description,
                'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
            ]);

            $loan->updateBalance();
            $this->logAudit($loan->id, 'updated', 'transaction', $transaction->id, $oldValues, $transaction->toArray());

            return $transaction;
        });
    }

    public function deleteTransaction(PettyCashTransaction $transaction): void
    {
        DB::transaction(function () use ($transaction) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($transaction->petty_cash_loan_id);
            if ($loan->status !== 'active') {
                throw new \Exception('Transactions can only be deleted on active loans.');
            }

            $oldValues = $transaction->toArray();

            // Also delete any associated media
            $transaction->clearMediaCollection('bills');
            $transaction->delete();

            $loan->updateBalance();
            $this->logAudit($loan->id, 'deleted', 'transaction', $transaction->id, $oldValues, null);
        });
    }

    // ─── Loan Status Operations ───

    public function closeLoan(PettyCashLoan $loan): PettyCashLoan
    {
        return DB::transaction(function () use ($loan) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $loan->update([
                'status' => 'closed',
                'closed_date' => now()->toDateString(),
            ]);

            $this->logAudit($loan->id, 'closed', 'loan', $loan->id);

            return $loan;
        });
    }

    public function approveLoan(PettyCashLoan $loan, ?string $comment = null): PettyCashLoan
    {
        return DB::transaction(function () use ($loan, $comment) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'pending_approval') {
                throw new \Exception('Only pending loans can be approved.');
            }

            $loan->update([
                'status' => 'active',
                'current_balance' => $loan->original_amount,
                'approved_by' => Auth::id(),
                'approval_comment' => $comment,
                'approved_at' => now(),
            ]);

            // Create initial loan_taken transaction
            $loan->transactions()->create([
                'type' => 'loan_taken',
                'amount' => $loan->original_amount,
                'description' => 'Initial loan amount',
                'transaction_date' => $loan->loan_date ?? now()->toDateString(),
            ]);

            $this->logAudit($loan->id, 'approved', 'loan', $loan->id, null, [
                'approved_by' => Auth::id(),
                'comment' => $comment,
            ]);

            return $loan;
        });
    }

    public function rejectLoan(PettyCashLoan $loan, ?string $comment = null): PettyCashLoan
    {
        return DB::transaction(function () use ($loan, $comment) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'pending_approval') {
                throw new \Exception('Only pending loans can be rejected.');
            }

            $loan->update([
                'status' => 'rejected',
                'closed_date' => now()->toDateString(),
                'approved_by' => Auth::id(),
                'approval_comment' => $comment,
                'rejected_at' => now(),
            ]);

            $this->logAudit($loan->id, 'rejected', 'loan', $loan->id, null, [
                'rejected_by' => Auth::id(),
                'comment' => $comment,
            ]);

            return $loan;
        });
    }

    // ─── Query Methods ───

    public function getLoanSummary(PettyCashLoan $loan): array
    {
        $transactions = $loan->transactions;

        return [
            'id' => $loan->id,
            'fund_name' => $loan->fund_name ?? 'General Fund',
            'original_amount' => $loan->original_amount,
            'current_balance' => $loan->current_balance,
            'total_expenses' => $transactions->where('type', 'expense')->sum('amount'),
            'total_reimbursements' => $transactions->where('type', 'reimbursement')->sum('amount'),
            'total_repayments' => $transactions->where('type', 'repayment')->sum('amount'),
            'status' => $loan->status,
            'loan_date' => $loan->loan_date,
            'transaction_count' => $transactions->count(),
            'approved_by' => $loan->approved_by,
            'approver_name' => $loan->approver?->name,
            'approval_comment' => $loan->approval_comment,
            'approved_at' => $loan->approved_at,
        ];
    }

    public function getTransactionHistory(PettyCashLoan $loan, array $filters = []): array
    {
        $page = (int) ($filters['page'] ?? 1);
        $perPage = (int) ($filters['per_page'] ?? 20);

        $query = $loan->transactions()->orderBy(
            $filters['sort_by'] ?? 'transaction_date',
            $filters['sort_order'] ?? 'desc'
        );

        // Server-side filters (Phase 4)
        if (!empty($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }
        if (!empty($filters['category']) && $filters['category'] !== 'all') {
            $query->where('category', $filters['category']);
        }
        if (!empty($filters['search'])) {
            $query->where('description', 'LIKE', '%' . $filters['search'] . '%');
        }
        if (!empty($filters['date_from'])) {
            $query->where('transaction_date', '>=', $filters['date_from']);
        }
        if (!empty($filters['date_to'])) {
            $query->where('transaction_date', '<=', $filters['date_to']);
        }
        if (!empty($filters['amount_min'])) {
            $query->where('amount', '>=', (float) $filters['amount_min']);
        }
        if (!empty($filters['amount_max'])) {
            $query->where('amount', '<=', (float) $filters['amount_max']);
        }

        $total = $query->count();
        $transactions = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $transactions->map(function ($transaction) {
                return [
                    'id' => $transaction->id,
                    'type' => $transaction->type,
                    'category' => $transaction->category,
                    'amount' => $transaction->amount,
                    'description' => $transaction->description,
                    'transaction_date' => $transaction->transaction_date,
                    'has_bills' => $transaction->getMedia('bills')->count() > 0,
                    'bills' => $transaction->getMedia('bills')->map(function ($media) {
                        return [
                            'id' => $media->id,
                            'name' => $media->file_name,
                            'url' => $media->getUrl(),
                        ];
                    }),
                ];
            }),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    public function getAnalytics(PettyCashLoan $loan): array
    {
        $transactions = $loan->transactions;

        $categoryBreakdown = $transactions
            ->where('type', 'expense')
            ->groupBy('category')
            ->map(function ($group) {
                return $group->sum('amount');
            });

        $monthlyTrends = $transactions
            ->groupBy(function ($transaction) {
                return date('Y-m', strtotime($transaction->transaction_date));
            })
            ->map(function ($group) {
                return [
                    'expenses' => $group->where('type', 'expense')->sum('amount'),
                    'reimbursements' => $group->where('type', 'reimbursement')->sum('amount'),
                    'repayments' => $group->where('type', 'repayment')->sum('amount'),
                ];
            });

        return [
            'category_breakdown' => $categoryBreakdown,
            'monthly_trends' => $monthlyTrends,
            'type_distribution' => [
                'loan_taken' => $transactions->where('type', 'loan_taken')->sum('amount'),
                'expense' => $transactions->where('type', 'expense')->sum('amount'),
                'reimbursement' => $transactions->where('type', 'reimbursement')->sum('amount'),
                'repayment' => $transactions->where('type', 'repayment')->sum('amount'),
            ],
        ];
    }

    // Phase 3: Multiple funds — return all active loans
    public function getUserActiveLoans(int $userId)
    {
        return PettyCashLoan::forUser($userId)->active()->orderBy('created_at', 'desc')->get();
    }

    // Keep single accessor for backward compat
    public function getUserActiveLoan(int $userId): ?PettyCashLoan
    {
        return PettyCashLoan::forUser($userId)->active()->first();
    }

    public function getUserPendingLoans(int $userId)
    {
        return PettyCashLoan::forUser($userId)->where('status', 'pending_approval')->get();
    }

    public function getUserPendingLoan(int $userId): ?PettyCashLoan
    {
        return PettyCashLoan::forUser($userId)->where('status', 'pending_approval')->first();
    }

    public function getUserLoanHistory(int $userId): array
    {
        return PettyCashLoan::forUser($userId)
            ->whereIn('status', ['closed', 'settled', 'rejected'])
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($loan) {
                return $this->getLoanSummary($loan);
            })
            ->toArray();
    }

    public function getAdminOverview(string $status = null): array
    {
        $query = PettyCashLoan::with(['user', 'approver'])->orderBy('created_at', 'desc');
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get()->map(function ($loan) {
            return [
                'id' => $loan->id,
                'fund_name' => $loan->fund_name ?? 'General Fund',
                'user' => [
                    'id' => $loan->user->id,
                    'name' => $loan->user->name,
                    'email' => $loan->user->email,
                ],
                'original_amount' => $loan->original_amount,
                'current_balance' => $loan->current_balance,
                'status' => $loan->status,
                'loan_date' => $loan->loan_date,
                'closed_date' => $loan->closed_date,
                'notes' => $loan->notes,
                'approved_by' => $loan->approved_by,
                'approver_name' => $loan->approver?->name,
                'approval_comment' => $loan->approval_comment,
                'approved_at' => $loan->approved_at,
                'rejected_at' => $loan->rejected_at,
            ];
        })->toArray();
    }

    // ─── Audit Trail (Phase 6) ───

    public function getAuditLog(int $loanId, int $page = 1, int $perPage = 20): array
    {
        $query = PettyCashAuditLog::where('petty_cash_loan_id', $loanId)
            ->with('user')
            ->orderBy('created_at', 'desc');

        $total = $query->count();
        $logs = $query->offset(($page - 1) * $perPage)
            ->limit($perPage)
            ->get();

        return [
            'data' => $logs->map(function ($log) {
                return [
                    'id' => $log->id,
                    'action' => $log->action,
                    'entity_type' => $log->entity_type,
                    'entity_id' => $log->entity_id,
                    'old_values' => $log->old_values,
                    'new_values' => $log->new_values,
                    'user_name' => $log->user?->name ?? 'System',
                    'ip_address' => $log->ip_address,
                    'created_at' => $log->created_at,
                ];
            }),
            'total' => $total,
            'page' => $page,
            'per_page' => $perPage,
            'last_page' => (int) ceil($total / $perPage),
        ];
    }

    protected function logAudit(int $loanId, string $action, string $entityType, int $entityId, ?array $oldValues = null, ?array $newValues = null): void
    {
        try {
            PettyCashAuditLog::create([
                'petty_cash_loan_id' => $loanId,
                'user_id' => Auth::id() ?? 0,
                'action' => $action,
                'entity_type' => $entityType,
                'entity_id' => $entityId,
                'old_values' => $oldValues,
                'new_values' => $newValues,
                'ip_address' => request()?->ip(),
                'created_at' => now(),
            ]);
        } catch (\Exception $e) {
            // Don't fail the main operation if audit logging fails
            \Log::warning('Petty cash audit log failed: ' . $e->getMessage());
        }
    }
}
