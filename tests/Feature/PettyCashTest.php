<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Http\UploadedFile;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PettyCashTest extends TestCase
{
    use RefreshDatabase;

    protected User $employee;
    protected User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        $this->employee = User::factory()->create();
        $this->manager = User::factory()->create();

        // Setup manager role
        $managerRole = Role::create(['name' => 'Manager']);
        $this->manager->assignRole($managerRole);
    }

    public function test_employee_can_request_loan(): void
    {
        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.loan'), [
            'amount' => 500.00,
            'loan_date' => now()->toDateString(),
            'notes' => 'Need money for team snacks'
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('loan.status', 'pending_approval');

        $this->assertDatabaseHas('petty_cash_loans', [
            'user_id' => $this->employee->id,
            'original_amount' => 500.00,
            'current_balance' => 0.00,
            'status' => 'pending_approval'
        ]);
    }

    public function test_employee_cannot_add_transaction_to_pending_loan(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 0.00,
            'status' => 'pending_approval',
            'loan_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.expense'), [
            'loan_id' => $loan->id,
            'amount' => 50.00,
            'category' => 'office_supplies',
            'description' => 'Notebooks',
        ]);

        $response->assertStatus(500); // Fails because loan is not active
    }

    public function test_manager_can_approve_loan(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 0.00,
            'status' => 'pending_approval',
            'loan_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(route('petty-cash.loan.approve'), [
            'loan_id' => $loan->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $loan->refresh();
        $this->assertEquals('active', $loan->status);
        $this->assertEquals(500.00, $loan->current_balance);

        $this->assertDatabaseHas('petty_cash_transactions', [
            'petty_cash_loan_id' => $loan->id,
            'type' => 'loan_taken',
            'amount' => 500.00
        ]);
    }

    public function test_manager_can_reject_loan(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 0.00,
            'status' => 'pending_approval',
            'loan_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->manager)->postJson(route('petty-cash.loan.reject'), [
            'loan_id' => $loan->id
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);

        $loan->refresh();
        $this->assertEquals('rejected', $loan->status);
    }

    public function test_employee_can_add_expense_to_active_loan(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 500.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.expense'), [
            'loan_id' => $loan->id,
            'amount' => 50.00,
            'category' => 'office_supplies',
            'description' => 'Files and pens',
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $loan->refresh();
        $this->assertEquals(450.00, $loan->current_balance);

        $this->assertDatabaseHas('petty_cash_transactions', [
            'petty_cash_loan_id' => $loan->id,
            'type' => 'expense',
            'amount' => 50.00
        ]);
    }

    public function test_employee_can_add_reimbursement(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 450.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $loan->transactions()->create([
            'type' => 'expense',
            'category' => 'office_supplies',
            'amount' => 50.00,
            'description' => 'Initial expense to reduce balance',
            'transaction_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.reimbursement'), [
            'loan_id' => $loan->id,
            'amount' => 30.00,
            'category' => 'office_supplies',
            'description' => 'Returned incorrect supplies',
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $loan->refresh();
        $this->assertEquals(480.00, $loan->current_balance);
    }

    public function test_employee_can_add_repayment_and_auto_settle(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 50.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $loan->transactions()->create([
            'type' => 'expense',
            'category' => 'office_supplies',
            'amount' => 450.00,
            'description' => 'Big expense to reduce balance to 50',
            'transaction_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.repayment'), [
            'loan_id' => $loan->id,
            'amount' => 50.00,
            'description' => 'Final cash return',
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertStatus(201);
        $loan->refresh();
        $this->assertEquals(0.00, $loan->current_balance);
        $this->assertEquals('settled', $loan->status);
    }

    public function test_employee_can_update_transaction(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 450.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $transaction = $loan->transactions()->create([
            'type' => 'expense',
            'category' => 'office_supplies',
            'amount' => 50.00,
            'description' => 'Pens',
            'transaction_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->putJson(route('petty-cash.transaction.update'), [
            'transaction_id' => $transaction->id,
            'amount' => 30.00,
            'category' => 'office_supplies',
            'description' => 'Fewer pens than expected',
            'transaction_date' => now()->toDateString(),
        ]);

        $response->assertStatus(200);
        $loan->refresh();
        $this->assertEquals(470.00, $loan->current_balance); // 500 - 30 = 470
    }

    public function test_employee_can_delete_transaction(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 450.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $transaction = $loan->transactions()->create([
            'type' => 'expense',
            'category' => 'office_supplies',
            'amount' => 50.00,
            'description' => 'Pens',
            'transaction_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->deleteJson(route('petty-cash.transaction.delete'), [
            'transaction_id' => $transaction->id
        ]);

        $response->assertStatus(200);
        $loan->refresh();
        $this->assertEquals(500.00, $loan->current_balance); // Returns to original 500
        $this->assertDatabaseMissing('petty_cash_transactions', ['id' => $transaction->id]);
    }

    public function test_employee_can_close_loan_manually(): void
    {
        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 400.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $response = $this->actingAs($this->employee)->postJson(route('petty-cash.loan.close'), [
            'loan_id' => $loan->id
        ]);

        $response->assertStatus(200);
        $loan->refresh();
        $this->assertEquals('closed', $loan->status);
        $this->assertNotNull($loan->closed_date);
    }
}
