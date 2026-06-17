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
                'current_balance' => $data['amount'],
                'status' => 'active',
                'loan_date' => $data['loan_date'] ?? now()->toDateString(),
                'notes' => $data['notes'] ?? null,
            ]);

            // Create initial loan_taken transaction
            $loan->transactions()->create([
                'type' => 'loan_taken',
                'amount' => $data['amount'],
                'description' => 'Initial loan amount',
                'transaction_date' => $data['loan_date'] ?? now()->toDateString(),
            ]);

            return $loan;
        });
    }

    public function addExpense(PettyCashLoan $loan, array $data): PettyCashTransaction
    {
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
}
