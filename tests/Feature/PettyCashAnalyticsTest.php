<?php

namespace Tests\Feature;

use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class PettyCashAnalyticsTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private PettyCashLoan $loan;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');
        Carbon::setTestNow('2026-09-17 10:00:00');

        $this->employee = User::factory()->create();

        $this->loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 10000,
            'original_amount' => 10000,
            'current_balance' => 10000,
            'status' => 'active',
            'loan_date' => '2026-07-01',
        ]);

        // July: two expenses. August: nothing. September: expense, reimbursement, repayment.
        $this->tx('expense', 1200, '2026-07-06', 'fuel');          // Monday
        $this->tx('expense', 300.50, '2026-07-10', 'office_supplies'); // Friday
        $this->tx('expense', 2000, '2026-09-07', 'fuel');          // Monday
        $this->tx('reimbursement', 500, '2026-09-08');
        $this->tx('repayment', 1000, '2026-09-10');

        $this->loan->load('transactions');
        $this->loan->updateBalance();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function tx(string $type, float $amount, string $date, ?string $category = null): PettyCashTransaction
    {
        return PettyCashTransaction::create([
            'petty_cash_loan_id' => $this->loan->id,
            'type' => $type,
            'category' => $category,
            'amount' => $amount,
            'description' => "{$type} {$amount}",
            'transaction_date' => $date,
        ]);
    }

    private function analytics(string $range = 'all')
    {
        return $this->actingAs($this->employee)
            ->getJson(route('petty-cash.analytics', ['loan_id' => $this->loan->id, 'range' => $range]))
            ->assertOk()
            ->json('analytics');
    }

    public function test_all_time_summary_matches_the_fund_balance(): void
    {
        $a = $this->analytics();

        $this->assertSame(10000.0, (float) $a['summary']['funded']);
        $this->assertSame(3500.5, (float) $a['summary']['spent']);
        $this->assertSame(500.0, (float) $a['summary']['reimbursed']);
        $this->assertSame(1000.0, (float) $a['summary']['repaid']);
        // 10000 - 3500.50 - 1000 + 500
        $this->assertSame(5999.5, (float) $a['summary']['closing_balance']);
        $this->assertSame((float) $this->loan->fresh()->current_balance, (float) $a['summary']['current_balance']);
        $this->assertSame(5999.5, (float) end($a['balance_timeline'])['balance']);
        $this->assertNull($a['comparison']);
    }

    public function test_months_are_zero_filled_and_categories_ranked_with_shares(): void
    {
        $a = $this->analytics();

        $this->assertSame(['2026-07', '2026-08', '2026-09'], array_column($a['monthly'], 'month'));
        $this->assertSame(0.0, (float) $a['monthly'][1]['expenses']);

        $this->assertSame('fuel', $a['categories'][0]['key']);
        $this->assertSame('Fuel', $a['categories'][0]['label']);
        $this->assertSame(3200.0, (float) $a['categories'][0]['amount']);
        $this->assertSame(2, $a['categories'][0]['count']);
        $this->assertEqualsWithDelta(91.4, $a['categories'][0]['share_pct'], 0.05);

        $weekday = collect($a['weekday'])->keyBy('day');
        $this->assertSame(2, $weekday['Mon']['count']);
        $this->assertSame(1, $weekday['Fri']['count']);

        $this->assertSame(2000.0, (float) $a['top_expenses'][0]['amount']);
    }

    public function test_a_range_scopes_every_figure_and_carries_the_opening_balance(): void
    {
        $a = $this->analytics('mtd');

        $this->assertSame('2026-09-01', $a['range']['from']);
        $this->assertSame(2000.0, (float) $a['summary']['spent']);
        // July's 1500.50 of spending happened before the window.
        $this->assertSame(8499.5, (float) $a['summary']['opening_balance']);
        $this->assertSame(5999.5, (float) $a['summary']['closing_balance']);
        $this->assertSame(['2026-09'], array_column($a['monthly'], 'month'));
        $this->assertNotNull($a['comparison']);
    }

    public function test_bill_coverage_counts_expenses_with_an_attached_bill(): void
    {
        $withBill = PettyCashTransaction::where('amount', 2000)->first();
        $this->actingAs($this->employee)->postJson(route('petty-cash.upload-bill'), [
            'transaction_id' => $withBill->id,
            'bill' => UploadedFile::fake()->image('bill.jpg'),
        ])->assertStatus(201);

        $bills = $this->analytics()['summary']['bills'];

        $this->assertSame(1, $bills['with']);
        $this->assertSame(2, $bills['without']);
        $this->assertEqualsWithDelta(33.3, $bills['coverage_pct'], 0.05);
    }

    public function test_an_unknown_range_is_rejected(): void
    {
        $this->actingAs($this->employee)
            ->getJson(route('petty-cash.analytics', ['loan_id' => $this->loan->id, 'range' => 'forever']))
            ->assertStatus(422);
    }
}
