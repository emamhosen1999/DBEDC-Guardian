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
use Barryvdh\DomPDF\Facade\Pdf;

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

    // ─── Helper: check if user is admin/manager ───
    private function isAdminOrManager($user): bool
    {
        try {
            return $user->hasRole('Super Administrator') ||
                   $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']) ||
                   $user->hasPermissionTo('petty-cash.approve');
        } catch (\Spatie\Permission\Exceptions\PermissionDoesNotExist $e) {
            return $user->hasRole('Super Administrator') ||
                   $user->hasAnyRole(['Manager', 'Accountant', 'Finance Manager']);
        }
    }

    // ─── Helper: check loan access (owner OR admin) ───
    private function canAccessLoan(PettyCashLoan $loan, $user): bool
    {
        return (int) $loan->user_id === (int) $user->id || $this->isAdminOrManager($user);
    }

    // ─── Page Render ───
    public function index()
    {
        $user = Auth::user();
        $activeLoans = $this->pettyCashService->getUserActiveLoans($user->id);
        $pendingLoans = $this->pettyCashService->getUserPendingLoans($user->id);
        $canApprove = $this->isAdminOrManager($user);

        return Inertia::render('PettyCashUnified', [
            'title' => 'Petty Cash Management',
            'activeLoans' => $activeLoans->map(fn($l) => $this->pettyCashService->getLoanSummary($l))->toArray(),
            'pendingLoans' => $pendingLoans->map(fn($l) => [
                'id' => $l->id,
                'fund_name' => $l->fund_name ?? 'General Fund',
                'original_amount' => $l->original_amount,
                'status' => $l->status,
                'loan_date' => $l->loan_date?->toDateString(),
                'notes' => $l->notes,
            ])->toArray(),
            'canApprove' => $canApprove,
            'categories' => $this->pettyCashService->getCategories(),
        ]);
    }

    // ─── Categories API ───
    public function getCategories()
    {
        return response()->json([
            'success' => true,
            'categories' => $this->pettyCashService->getCategories(),
        ]);
    }

    // ─── Loan CRUD ───
    public function createLoan(Request $request)
    {
        $request->validate([
            'amount' => 'required|numeric|min:1',
            'loan_date' => 'nullable|date',
            'notes' => 'nullable|string|max:1000',
            'fund_name' => 'nullable|string|max:255',
        ]);

        try {
            $loan = $this->pettyCashService->createLoan($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Loan request submitted successfully',
                'loan' => $this->pettyCashService->getLoanSummary($loan),
            ], 201);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to create loan: '.$e->getMessage(),
            ], 500);
        }
    }

    // ─── Transaction Operations ───
    public function addExpense(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'required|string|max:100',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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
            'category' => 'nullable|string|max:100',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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

    // ─── Transaction History with Server-side Filtering (Phase 4) ───
    public function getTransactions(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
            'type' => 'nullable|string',
            'category' => 'nullable|string',
            'search' => 'nullable|string|max:200',
            'date_from' => 'nullable|date',
            'date_to' => 'nullable|date',
            'amount_min' => 'nullable|numeric|min:0',
            'amount_max' => 'nullable|numeric|min:0',
            'sort_by' => 'nullable|in:transaction_date,amount,type,category',
            'sort_order' => 'nullable|in:asc,desc',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to this loan',
                ], 403);
            }

            $transactions = $this->pettyCashService->getTransactionHistory($loan, $request->all());

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

    // ─── Analytics ───
    public function getAnalytics(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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

    // ─── Bill Management ───
    public function uploadBill(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
            'bill' => 'required|file|mimes:jpeg,jpg,png,pdf|max:5120',
        ]);

        try {
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($transaction->pettyCashLoan, $user)) {
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
            $user = Auth::user();

            if (!$this->canAccessLoan($transaction->pettyCashLoan, $user)) {
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

    // ─── Export ───
    public function exportData(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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

    public function exportPdf(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
                abort(403, 'Unauthorized access to this loan');
            }

            // Get all transactions for this loan (no page pagination limit)
            $transactionsData = $this->pettyCashService->getTransactionHistory($loan, [
                'per_page' => 999999,
                'sort_by' => 'transaction_date',
                'sort_order' => 'asc' // Chronological order is better for PDF ledger
            ]);

            $summary = $this->pettyCashService->getLoanSummary($loan);
            $categories = $this->pettyCashService->getCategories();

            $pdf = Pdf::loadView('petty_cash_report', [
                'loan' => $loan,
                'summary' => $summary,
                'transactions' => $transactionsData['data'],
                'categories' => $categories,
                'generated_at' => now(),
            ]);

            $filename = 'petty_cash_report_' . ($loan->fund_name ? str_replace(' ', '_', $loan->fund_name) : 'fund') . '_' . now()->format('Y_m_d') . '.pdf';

            return $pdf->download($filename);
        } catch (\Exception $e) {
            return back()->with('error', 'Failed to generate PDF: ' . $e->getMessage());
        }
    }

    // ─── Transaction Edit/Delete ───
    public function updateTransaction(Request $request)
    {
        $request->validate([
            'transaction_id' => 'required|exists:petty_cash_transactions,id',
            'amount' => 'required|numeric|min:0.01',
            'category' => 'nullable|string|max:100',
            'description' => 'required|string|max:1000',
            'transaction_date' => 'nullable|date',
        ]);

        try {
            $transaction = PettyCashTransaction::findOrFail($request->transaction_id);
            $loan = $transaction->pettyCashLoan;
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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

    // ─── Loan Status Actions ───
    public function closeLoan(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            if (!$this->canAccessLoan($loan, $user)) {
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

    // ─── Approval with Comments (Phase 5) ───
    public function approveLoan(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        if (!$this->isAdminOrManager($user)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only admins or managers can approve loans.',
            ], 403);
        }

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $approved = $this->pettyCashService->approveLoan($loan, $request->comment);

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
            'comment' => 'nullable|string|max:1000',
        ]);

        $user = Auth::user();
        if (!$this->isAdminOrManager($user)) {
            return response()->json([
                'success' => false,
                'error' => 'Unauthorized. Only admins or managers can reject loans.',
            ], 403);
        }

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $rejected = $this->pettyCashService->rejectLoan($loan, $request->comment);

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

    // ─── History ───
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

    // ─── Admin Overview ───
    public function getAdminOverview(Request $request)
    {
        $user = Auth::user();
        if (!$this->isAdminOrManager($user)) {
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

    // ─── Audit Log (Phase 6) ───
    public function getAuditLog(Request $request)
    {
        $request->validate([
            'loan_id' => 'required|exists:petty_cash_loans,id',
            'page' => 'nullable|integer|min:1',
            'per_page' => 'nullable|integer|min:1|max:100',
        ]);

        try {
            $loan = PettyCashLoan::findOrFail($request->loan_id);
            $user = Auth::user();

            // Only loan owner or admin can see audit logs
            if (!$this->canAccessLoan($loan, $user)) {
                return response()->json([
                    'success' => false,
                    'error' => 'Unauthorized access to audit log',
                ], 403);
            }

            $auditLog = $this->pettyCashService->getAuditLog(
                $loan->id,
                $request->input('page', 1),
                $request->input('per_page', 20)
            );

            return response()->json([
                'success' => true,
                'audit_log' => $auditLog,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'error' => 'Failed to fetch audit log: '.$e->getMessage(),
            ], 500);
        }
    }

    // ─── Export Status Check ───
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
