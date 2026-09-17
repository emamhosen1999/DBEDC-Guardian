<?php

namespace Tests\Feature;

use App\Models\PettyCashLoan;
use App\Models\PettyCashTransaction;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Bill attachments on a petty-cash transaction.
 *
 * Regression: the model called MediaCollection::maxNumberOfFiles(), which does
 * not exist in spatie/laravel-medialibrary, so every upload failed with a 500.
 * The cap is now enforced by the service and reported as a validation error.
 */
class PettyCashBillUploadTest extends TestCase
{
    use RefreshDatabase;

    private User $employee;
    private PettyCashTransaction $transaction;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('public');

        $this->employee = User::factory()->create();

        $loan = PettyCashLoan::create([
            'user_id' => $this->employee->id,
            'loan_amount' => 500.00,
            'original_amount' => 500.00,
            'current_balance' => 500.00,
            'status' => 'active',
            'loan_date' => now()->toDateString(),
        ]);

        $this->transaction = PettyCashTransaction::create([
            'petty_cash_loan_id' => $loan->id,
            'type' => 'expense',
            'category' => 'office_supplies',
            'amount' => 50.00,
            'description' => 'Notebooks',
            'transaction_date' => now()->toDateString(),
        ]);
    }

    private function upload(string $name = 'bill.jpg')
    {
        return $this->actingAs($this->employee)->postJson(route('petty-cash.upload-bill'), [
            'transaction_id' => $this->transaction->id,
            'bill' => UploadedFile::fake()->image($name),
        ]);
    }

    public function test_a_bill_can_be_attached_to_a_transaction(): void
    {
        $response = $this->upload();

        $response->assertStatus(201)
            ->assertJsonPath('success', true)
            ->assertJsonPath('bill.is_image', true);

        $this->assertCount(1, $this->transaction->fresh()->getMedia('bills'));
    }

    public function test_the_eleventh_bill_is_refused_rather_than_replacing_the_oldest(): void
    {
        for ($i = 1; $i <= PettyCashTransaction::MAX_BILLS; $i++) {
            $this->upload("bill-{$i}.jpg")->assertStatus(201);
        }

        $response = $this->upload('one-too-many.jpg');

        $response->assertStatus(422)
            ->assertJsonPath('success', false)
            ->assertJsonValidationErrors(['bill']);

        // Nothing was silently dropped to make room.
        $this->assertCount(PettyCashTransaction::MAX_BILLS, $this->transaction->fresh()->getMedia('bills'));
    }
}
