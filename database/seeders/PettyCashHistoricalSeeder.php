<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\PettyCashLoan;
use Illuminate\Support\Facades\DB;

class PettyCashHistoricalSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $userId = 18;

        DB::transaction(function () use ($userId) {
            // Clean up existing loans/transactions for User 18
            DB::table('petty_cash_transactions')->whereIn('petty_cash_loan_id', function($q) use ($userId) {
                $q->select('id')->from('petty_cash_loans')->where('user_id', $userId);
            })->delete();
            DB::table('petty_cash_loans')->where('user_id', $userId)->delete();

            // 1. Create the initial loan prior to June 28 (set loan_date to 2026-06-15)
            $loan = PettyCashLoan::create([
                'user_id' => $userId,
                'loan_amount' => 41955.00,
                'original_amount' => 41955.00,
                'current_balance' => 41955.00,
                'status' => 'active',
                'loan_date' => '2026-06-15',
            ]);

            // Log initial loan taken transaction
            $loan->transactions()->create([
                'type' => 'loan_taken',
                'amount' => 41955.00,
                'description' => 'Initial petty cash received from boss',
                'transaction_date' => '2026-06-15',
            ]);

            // 2. Add expenses and reimbursements chronologically
            $events = [
                // Expenses
                ['type' => 'expense', 'date' => '2026-06-16', 'amount' => 5405.00, 'category' => 'services', 'desc' => 'Fuel bought (47 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-18', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-21', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-23', 'amount' => 3450.00, 'category' => 'services', 'desc' => 'Fuel bought (30 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-24', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-26', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-27', 'amount' => 5750.00, 'category' => 'services', 'desc' => 'Fuel bought (50 Liters)'],
                
                // Top-up June 28/29
                ['type' => 'reimbursement', 'date' => '2026-06-28', 'amount' => 25000.00, 'category' => null, 'desc' => 'Funds received from boss (Top-Up)'],
                
                // Expenses
                ['type' => 'expense', 'date' => '2026-06-28', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-06-29', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                
                // Top-up July 2
                ['type' => 'reimbursement', 'date' => '2026-07-02', 'amount' => 16000.00, 'category' => null, 'desc' => 'Funds received from boss (Top-Up)'],
                
                // Expense July 2
                ['type' => 'expense', 'date' => '2026-07-02', 'amount' => 13800.00, 'category' => 'services', 'desc' => 'Fuel bought (120 Liters)'],
                
                // Top-up July 7
                ['type' => 'reimbursement', 'date' => '2026-07-07', 'amount' => 34000.00, 'category' => null, 'desc' => 'Funds received from boss (Top-Up)'],
                
                // Expenses
                ['type' => 'expense', 'date' => '2026-07-07', 'amount' => 14000.00, 'category' => 'services', 'desc' => 'Fuel bought (120 Liters)'],
                ['type' => 'expense', 'date' => '2026-07-08', 'amount' => 14200.00, 'category' => 'services', 'desc' => 'Fuel bought (120 Liters)'],
                
                // Top-up July 11
                ['type' => 'reimbursement', 'date' => '2026-07-11', 'amount' => 30000.00, 'category' => null, 'desc' => 'Funds received from boss (Top-Up)'],
                
                // Expenses
                ['type' => 'expense', 'date' => '2026-07-11', 'amount' => 10350.00, 'category' => 'services', 'desc' => 'Fuel bought (90 Liters)'],
                ['type' => 'expense', 'date' => '2026-07-14', 'amount' => 14000.00, 'category' => 'services', 'desc' => 'Fuel bought (121.74 Liters)'],
                ['type' => 'expense', 'date' => '2026-07-14', 'amount' => 1200.00, 'category' => 'office_supplies', 'desc' => 'Empty Barrels (3 units)'],
            ];

            foreach ($events as $event) {
                $loan->transactions()->create([
                    'type' => $event['type'],
                    'amount' => $event['amount'],
                    'category' => $event['category'],
                    'description' => $event['desc'],
                    'transaction_date' => $event['date'],
                ]);
            }

            $loan->updateBalance();
            $this->command->info("Successfully seeded petty cash for user ID " . $userId . ". Balance: " . $loan->current_balance);
        });
    }
}
