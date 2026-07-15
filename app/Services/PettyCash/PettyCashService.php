<?php

namespace App\Services\PettyCash;

use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class PettyCashService
{
    public function createLoan(array $data): PettyCashLoan
    {
        return DB::transaction(function () use ($data) {
            $loan = PettyCashLoan::create([
                'user_id' => Auth::id(),
                'loan_amount' => $data['amount'],
                'original_amount' => $data['amount'],
                'current_balance' => 0.00,
                'status' => 'pending_approval',
                'loan_date' => $data['loan_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            return $loan;
        });
    }

    public function addExpense(PettyCashLoan $loan, array $data): PettyCashTransaction
    {
        if ($loan->status !== 'active') {
            throw new \Exception('Cannot add expense to a non-active loan.');
        }

        return DB::transaction(function () use ($loan, $data) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $transaction = $loan->transactions()->create([
                'type' => 'expense',
                'category' => $data['category'],
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            ]);

            $loan->updateBalance();

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
            $transaction = $loan->transactions()->create([
                'type' => 'reimbursement',
                'category' => $data['category'] ?? null,
                'amount' => $data['amount'],
                'description' => $data['description'],
                'transaction_date' => $data['transaction_date'] ?? now()->toDateString(),
            ]);

            $loan->updateBalance();

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

            return $transaction;
        });
    }

    public function closeLoan(PettyCashLoan $loan): PettyCashLoan
    {
        return DB::transaction(function () use ($loan) {
            $loan = PettyCashLoan::lockForUpdate()->find($loan->id);
            $loan->update([
                'status' => 'closed',
                'closed_date' => now()->toDateString(),
            ]);

            return $loan;
        });
    }

    public function getLoanSummary(PettyCashLoan $loan): array
    {
        $transactions = $loan->transactions;

        return [
            'id' => $loan->id,
            'original_amount' => $loan->original_amount,
            'current_balance' => $loan->current_balance,
            'total_expenses' => $transactions->where('type', 'expense')->sum('amount'),
            'total_reimbursements' => $transactions->where('type', 'reimbursement')->sum('amount'),
            'total_repayments' => $transactions->where('type', 'repayment')->sum('amount'),
            'status' => $loan->status,
            'loan_date' => $loan->loan_date,
            'transaction_count' => $transactions->count(),
        ];
    }

    public function getTransactionHistory(PettyCashLoan $loan, int $page = 1, int $perPage = 20): array
    {
        $query = $loan->transactions()->orderBy('transaction_date', 'desc');

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
            'last_page' => ceil($total / $perPage),
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

    public function getUserActiveLoan(int $userId): ?PettyCashLoan
    {
        return PettyCashLoan::forUser($userId)->active()->first();
    }

    public function getUserPendingLoan(int $userId): ?PettyCashLoan
    {
        return PettyCashLoan::forUser($userId)->where('status', 'pending_approval')->first();
    }

    public function approveLoan(PettyCashLoan $loan): PettyCashLoan
    {
        return DB::transaction(function () use ($loan) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'pending_approval') {
                throw new \Exception('Only pending loans can be approved.');
            }

            $loan->update([
                'status' => 'active',
                'current_balance' => $loan->original_amount,
            ]);

            // Create initial loan_taken transaction
            $loan->transactions()->create([
                'type' => 'loan_taken',
                'amount' => $loan->original_amount,
                'description' => 'Initial loan amount',
                'transaction_date' => $loan->loan_date ?? now()->toDateString(),
            ]);

            return $loan;
        });
    }

    public function rejectLoan(PettyCashLoan $loan): PettyCashLoan
    {
        return DB::transaction(function () use ($loan) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($loan->id);
            if ($loan->status !== 'pending_approval') {
                throw new \Exception('Only pending loans can be rejected.');
            }

            $loan->update([
                'status' => 'rejected',
                'closed_date' => now()->toDateString(),
            ]);

            return $loan;
        });
    }

    public function updateTransaction(PettyCashTransaction $transaction, array $data): PettyCashTransaction
    {
        return DB::transaction(function () use ($transaction, $data) {
            $loan = PettyCashLoan::lockForUpdate()->findOrFail($transaction->petty_cash_loan_id);
            if ($loan->status !== 'active') {
                throw new \Exception('Transactions can only be updated on active loans.');
            }

            $transaction->update([
                'category' => $data['category'] ?? $transaction->category,
                'amount' => $data['amount'] ?? $transaction->amount,
                'description' => $data['description'] ?? $transaction->description,
                'transaction_date' => $data['transaction_date'] ?? $transaction->transaction_date,
            ]);

            $loan->updateBalance();

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

            // Also delete any associated media
            $transaction->clearMediaCollection('bills');
            $transaction->delete();

            $loan->updateBalance();
        });
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
        $query = PettyCashLoan::with('user')->orderBy('created_at', 'desc');
        if ($status) {
            $query->where('status', $status);
        }

        return $query->get()->map(function ($loan) {
            return [
                'id' => $loan->id,
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
            ];
        })->toArray();
    }
}
