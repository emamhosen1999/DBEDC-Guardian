<?php

namespace App\Http\Controllers;

use App\Models\PettyCashLoan;
use App\Services\PettyCash\PettyCashFileService;
use App\Services\PettyCash\PettyCashService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;

class PettyCashController extends Controller
{
    protected PettyCashService $pettyCashService;
    protected PettyCashFileService $fileService;

    public function __construct(
        PettyCashService $pettyCashService,
        PettyCashFileService $fileService
    ) {
        $this->pettyCashService = $pettyCashService;
        $this->fileService = $fileService;
    }

    public function index()
    {
        $user = Auth::user();
        $activeLoan = $this->pettyCashService->getUserActiveLoan($user->id);

        return Inertia::render('PettyCashUnified', [
            'title' => 'Petty Cash Management',
            'activeLoan' => $activeLoan ? $this->pettyCashService->getLoanSummary($activeLoan) : null,
        ]);
    }

    public function createLoan(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'loan_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        try {
            $loan = $this->pettyCashService->createLoan($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Loan created successfully',
                'loan' => $this->pettyCashService->getLoanSummary($loan),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create loan: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function addExpense(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|in:office_supplies,meeting_supplies,office_maintenance,services',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $transaction = $this->pettyCashService->addExpense($loan, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Expense added successfully',
                'transaction' => $transaction,
                'loan_summary' => $this->pettyCashService->getLoanSummary($loan),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to add expense: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function addReimbursement(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'nullable|in:office_supplies,meeting_supplies,office_maintenance,services',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $transaction = $this->pettyCashService->addReimbursement($loan, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Reimbursement added successfully',
                'transaction' => $transaction,
                'loan_summary' => $this->pettyCashService->getLoanSummary($loan),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to add reimbursement: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function addRepayment(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'amount' => 'required|numeric|min:0.01',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $transaction = $this->pettyCashService->addRepayment($loan, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Repayment added successfully',
                'transaction' => $transaction,
                'loan_summary' => $this->pettyCashService->getLoanSummary($loan),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to add repayment: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getTransactions(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $page = $request->input('page', 1);
            $perPage = $request->input('per_page', 20);

            $transactions = $this->pettyCashService->getTransactionHistory($loan, $page, $perPage);

            return response()->json([
                'success' => true,
                'transactions' => $transactions,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch transactions: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function getAnalytics(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $analytics = $this->pettyCashService->getAnalytics($loan);

            return response()->json([
                'success' => true,
                'analytics' => $analytics,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch analytics: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function uploadBill(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
            'bill' => 'required|file|mimes:jpeg,jpg,png,pdf|max:5120',
        ]);

        try {
            $transaction = \App\Models\PettyCashTransaction::findOrFail($request->transaction_id);

            // Ensure user owns the loan for this transaction
            if ($transaction->pettyCashLoan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this transaction',
                ], 403);
            }

            $bill = $this->fileService->uploadBill($transaction, $request->file('bill'));

            return response()->json([
                'success' => true,
                'message' => 'Bill uploaded successfully',
                'bill' => $bill,
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to upload bill: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function deleteBill(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
            'media_id' => 'required|integer',
        ]);

        try {
            $transaction = \App\Models\PettyCashTransaction::findOrFail($request->transaction_id);

            // Ensure user owns the loan for this transaction
            if ($transaction->pettyCashLoan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this transaction',
                ], 403);
            }

            $deleted = $this->fileService->deleteBill($transaction, $request->media_id);

            if ($deleted) {
                return response()->json([
                    'success' => true,
                    'message' => 'Bill deleted successfully',
                ]);
            }

            return response()->json([
                'success' => false,
                'error' => 'Bill not found',
            ], 404);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete bill: ' . $e->getMessage(),
            ], 500);
        }
    }

    public function exportData(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            // Ensure user owns this loan
            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $transactions = $this->pettyCashService->getTransactionHistory($loan, 1, 1000);

            $exportData = collect($transactions['data'])->map(function ($transaction) {
                return [
                    'Date' => $transaction['transaction_date'],
                    'Type' => ucfirst(str_replace('_', ' ', $transaction['type'])),
                    'Category' => $transaction['category'] ? ucfirst(str_replace('_', ' ', $transaction['category'])) : 'N/A',
                    'Amount' => $transaction['amount'],
                    'Description' => $transaction['description'],
                    'Has Bills' => $transaction['has_bills'] ? 'Yes' : 'No',
                ];
            });

            return response()->json([
                'success' => true,
                'data' => $exportData,
                'filename' => 'petty_cash_transactions_' . now()->format('Y_m_d_H_i_s'),
                'total_records' => count($exportData),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to export data: ' . $e->getMessage(),
            ], 500);
        }
    }
}
