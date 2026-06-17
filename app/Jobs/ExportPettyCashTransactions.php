<?php

namespace App\Jobs;

use App\Models\PettyCashLoan;
use App\Services\PettyCash\PettyCashService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;

class ExportPettyCashTransactions implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected int $loanId;

    protected int $userId;

    protected string $filename;

    /**
     * Create a new job instance.
     */
    public function __construct(int $loanId, int $userId, string $filename)
    {
        $this->loanId = $loanId;
        $this->userId = $userId;
        $this->filename = $filename;
    }

    /**
     * Execute the job.
     */
    public function handle(PettyCashService $pettyCashService): void
    {
        try {
            Log::info("ExportPettyCashTransactions started: LoanId={$this->loanId}, File={$this->filename}");

            // Ensure exports directory exists
            if (! Storage::disk('public')->exists('exports')) {
                Storage::disk('public')->makeDirectory('exports');
            }

            $loan = PettyCashLoan::findOrFail($this->loanId);

            // Fetch transaction history
            $transactions = $pettyCashService->getTransactionHistory($loan, 1, 10000); // Higher limit for background task

            $filePath = Storage::disk('public')->path('exports/'.$this->filename);

            $file = fopen($filePath, 'w');

            // Add UTF-8 BOM for Excel compatibility
            fwrite($file, chr(0xEF).chr(0xBB).chr(0xBF));

            // Write CSV headers
            fputcsv($file, [
                'Date',
                'Type',
                'Category',
                'Amount',
                'Description',
                'Has Bills',
            ]);

            // Write CSV rows
            foreach ($transactions['data'] as $transaction) {
                fputcsv($file, [
                    $transaction['transaction_date'],
                    ucfirst(str_replace('_', ' ', $transaction['type'])),
                    $transaction['category'] ? ucfirst(str_replace('_', ' ', $transaction['category'])) : 'N/A',
                    $transaction['amount'],
                    $transaction['description'],
                    $transaction['has_bills'] ? 'Yes' : 'No',
                ]);
            }

            fclose($file);

            Log::info("ExportPettyCashTransactions completed: File={$this->filename}");
        } catch (\Exception $e) {
            Log::error('ExportPettyCashTransactions failed: '.$e->getMessage(), [
                'loan_id' => $this->loanId,
                'file' => $this->filename,
                'exception' => $e,
            ]);
            throw $e;
        }
    }
}
