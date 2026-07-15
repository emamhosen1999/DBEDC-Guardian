<?php

namespace App\Http\Controllers;

use App\Jobs\ExportPettyCashTransactions;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
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
        $pendingLoan = $this->pettyCashService->getUserPendingLoan($user->id);

        $canApprove = false;
        try {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) || 
                          $user->hasPermissionTo('petty-cash.approve');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
        }

        return Inertia::render('PettyCashUnified', [
            'title' => 'Petty Cash Management',
            'activeLoan' => $activeLoan ? $this->pettyCashService->getLoanSummary($activeLoan) : null,
            'pendingLoan' => $pendingLoan ? [
                'id' => $pendingLoan->id,
                'original_amount' => $pendingLoan->original_amount,
                'status' => $pendingLoan->status,
                'loan_date' => $pendingLoan->loan_date ? $pendingLoan->loan_date->toDateString() : null,
                'notes' => $pendingLoan->notes,
            ] : null,
            'canApprove' => $canApprove,
        ]);
    }

    public function createLoan(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:0.01',
            'loan_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();

        // Check if there is an active loan
        if ($this->pettyCashService->getUserActiveLoan($user->id)) {
            return response()->json([
                'success' => false,
                'error' => 'You already have an active loan. Please close or repay it before requesting a new one.',
            ], 422);
        }

        // Check if there is a pending loan
        if ($this->pettyCashService->getUserPendingLoan($user->id)) {
            return response()->json([
                'success' => false,
                'error' => 'You already have a loan request pending approval.',
            ], 422);
        }

        try {
            $loan = $this->pettyCashService->createLoan($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Loan request submitted successfully and is pending approval.',
                'loan' => [
                    'id' => $loan->id,
                    'original_amount' => $loan->original_amount,
                    'status' => $loan->status,
                    'loan_date' => $loan->loan_date ? $loan->loan_date->toDateString() : null,
                    'notes' => $loan->notes,
                ],
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create loan: '.$e->getMessage(),
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
                'error' => 'Failed to add expense: '.$e->getMessage(),
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
                'error' => 'Failed to add reimbursement: '.$e->getMessage(),
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
                'error' => 'Failed to add repayment: '.$e->getMessage(),
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
                'error' => 'Failed to fetch transactions: '.$e->getMessage(),
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

            // Ensure user owns this loan OR is manager/admin
            $user = Auth::user();
            $canApprove = false;
            try {
                $canApprove = $user->hasRole('Super Administrator') || 
                              $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) || 
                              $user->hasPermissionTo('petty-cash.approve');
            } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
                $canApprove = $user->hasRole('Super Administrator') || 
                              $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
            }

            if ($loan->user_id !== $user->id && !$canApprove) {
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
                'error' => 'Failed to fetch analytics: '.$e->getMessage(),
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
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);

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
                'error' => 'Failed to upload bill: '.$e->getMessage(),
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
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);

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
                'error' => 'Failed to delete bill: '.$e->getMessage(),
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

            $filename = 'petty_cash_transactions_'.now()->format('Y_m_d_H_i_s').'_'.time().'.csv';

            ExportPettyCashTransactions::dispatch($loan->id, Auth::id(), $filename);

            return response()->json([
                'success' => true,
                'queued' => true,
                'filename' => $filename,
                'download_url' => asset('storage/exports/'.$filename),
                'message' => 'Export job has been dispatched.',
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to export data: '.$e->getMessage(),
            ], 500);
        }
    }

    public function approveLoan(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        $user = Auth::user();
        $canApprove = false;
        try {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) || 
                          $user->hasPermissionTo('petty-cash.approve');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
        }

        if (! $canApprove) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only admins or managers can approve loans.',
            ], 403);
        }

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $approved = $this->pettyCashService->approveLoan($loan);

            return response()->json([
                'success' => true,
                'message' => 'Loan approved successfully',
                'loan' => $this->pettyCashService->getLoanSummary($approved),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to approve loan: '.$e->getMessage(),
            ], 500);
        }
    }

    public function rejectLoan(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        $user = Auth::user();
        $canApprove = false;
        try {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) || 
                          $user->hasPermissionTo('petty-cash.approve');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
        }

        if (! $canApprove) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only admins or managers can reject loans.',
            ], 403);
        }

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $rejected = $this->pettyCashService->rejectLoan($loan);

            return response()->json([
                'success' => true,
                'message' => 'Loan rejected successfully',
                'loan' => [
                    'id' => $rejected->id,
                    'status' => $rejected->status,
                ],
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to reject loan: '.$e->getMessage(),
            ], 500);
        }
    }

    public function updateTransaction(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'nullable|in:office_supplies,meeting_supplies,office_maintenance,services',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);
            $loan = $transaction->pettyCashLoan;

            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this transaction',
                ], 403);
            }

            $updated = $this->pettyCashService->updateTransaction($transaction, $request->all());

            return response()->json([
                'success' => true,
                'message' => 'Transaction updated successfully',
                'transaction' => $updated,
                'loan_summary' => $this->pettyCashService->getLoanSummary($loan),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to update transaction: '.$e->getMessage(),
            ], 500);
        }
    }

    public function deleteTransaction(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
        ]);

        try {
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);
            $loan = $transaction->pettyCashLoan;

            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this transaction',
                ], 403);
            }

            $this->pettyCashService->deleteTransaction($transaction);

            return response()->json([
                'success' => true,
                'message' => 'Transaction deleted successfully',
                'loan_summary' => $this->pettyCashService->getLoanSummary($loan),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to delete transaction: '.$e->getMessage(),
            ], 500);
        }
    }

    public function closeLoan(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);

            if ($loan->user_id !== Auth::id()) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $closed = $this->pettyCashService->closeLoan($loan);

            return response()->json([
                'success' => true,
                'message' => 'Loan closed successfully',
                'loan' => $this->pettyCashService->getLoanSummary($closed),
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to close loan: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getHistory()
    {
        try {
            $history = $this->pettyCashService->getUserLoanHistory(Auth::id());

            return response()->json([
                'success' => true,
                'history' => $history,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch history: '.$e->getMessage(),
            ], 500);
        }
    }

    public function getAdminOverview(Request $request)
    {
        $user = Auth::user();
        $canApprove = false;
        try {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) || 
                          $user->hasPermissionTo('petty-cash.approve');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            $canApprove = $user->hasRole('Super Administrator') || 
                          $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
        }

        if (! $canApprove) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only admins or managers can access the admin overview.',
            ], 403);
        }

        try {
            $status = $request->query('status');
            $overview = $this->pettyCashService->getAdminOverview($status);

            return response()->json([
                'success' => true,
                'loans' => $overview,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch admin overview: '.$e->getMessage(),
            ], 500);
        }
    }

    public function checkExportStatus($filename)
    {
        $exists = \Illuminate\Support\Facades\Storage::disk('public')->exists('exports/'.$filename);
        if ($exists) {
            return response()->json([
                'status' => 'ready',
                'url' => asset('storage/exports/'.$filename)
            ]);
        }

        return response()->json(['status' => 'processing'], 202);
    }
}
