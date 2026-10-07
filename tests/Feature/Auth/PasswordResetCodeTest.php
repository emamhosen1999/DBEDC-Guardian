<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use App\Services\ModernAuthenticationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The password-reset OTP is a credential: stored hashed, every wrong guess counts toward the lockout, and the
 * reset form never confirms whether an address has an account.
 */
class PasswordResetCodeTest extends TestCase
{
    use RefreshDatabase;

    private function issue(string $email): array
    {
        return app(ModernAuthenticationService::class)->generatePasswordResetToken($email, Request::create('/forgot-password', 'POST'));
    }

    private function row(string $email): object
    {
        return DB::table('password_reset_tokens_secure')->where('email', $email)->latest('id')->first();
    }

    public function test_the_code_is_stored_hashed_and_still_verifies(): void
    {
        $issued = $this->issue('hash.me@example.test');
        $stored = $this->row('hash.me@example.test')->verification_code;

        $this->assertNotSame($issued['verification_code'], $stored);
        $this->assertTrue(Hash::check($issued['verification_code'], $stored));
        $this->assertTrue(app(ModernAuthenticationService::class)->verifyPasswordResetToken('hash.me@example.test', $issued['token'], $issued['verification_code']));
    }

    public function test_every_wrong_code_counts_and_five_lock_the_request(): void
    {
        $issued = $this->issue('guess.me@example.test');
        $service = app(ModernAuthenticationService::class);
        $wrong = $issued['verification_code'] === '000000' ? '111111' : '000000';

        for ($i = 1; $i <= 5; $i++) {
            $this->assertFalse($service->verifyPasswordResetToken('guess.me@example.test', $issued['token'], $wrong));
            $this->assertSame($i, (int) $this->row('guess.me@example.test')->attempts);
        }

        $this->assertFalse($service->verifyPasswordResetToken('guess.me@example.test', $issued['token'], $issued['verification_code']), 'locked after five wrong codes');
    }

    public function test_a_wrong_link_token_never_verifies(): void
    {
        $issued = $this->issue('token.me@example.test');

        $this->assertFalse(app(ModernAuthenticationService::class)->verifyPasswordResetToken('token.me@example.test', 'not-the-token', $issued['verification_code']));
    }

    public function test_the_reset_form_answers_an_unknown_address_like_a_wrong_code(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'known.person@example.test']);
        $issued = $this->issue($user->email);
        $payload = fn (string $email, string $code) => [
            'token' => $issued['token'], 'email' => $email, 'verification_code' => $code,
            'password' => 'New-password-123!', 'password_confirmation' => 'New-password-123!',
        ];
        $wrong = $issued['verification_code'] === '000000' ? '111111' : '000000';

        $unknown = $this->post('/reset-password', $payload('nobody.at.all@example.test', $wrong))->assertSessionHasErrors('verification_code');
        $known = $this->post('/reset-password', $payload($user->email, $wrong))->assertSessionHasErrors('verification_code');

        $this->assertSame(
            $unknown->getSession()->get('errors')->get('verification_code'),
            $known->getSession()->get('errors')->get('verification_code'),
        );
        $this->assertFalse($unknown->getSession()->get('errors')->has('email'));
    }
}
