<?php

namespace Tests\Feature\Auth;

use App\Mail\Auth\PasswordChangedNotificationMail;
use App\Mail\Auth\SecurePasswordResetMail;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** F-2: forgot-password really sends the mail, never logs the OTP, and confirms a change by mail. */
class PasswordResetMailTest extends TestCase
{
    use RefreshDatabase;

    private const STRONG = 'Str0ng!Passw0rd#2026';

    private array $logged = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Event::listen(MessageLogged::class, function (MessageLogged $e) {
            $this->logged[] = $e->message.' '.json_encode($e->context);
        });
    }

    public function test_forgot_password_sends_the_mail_and_keeps_otp_and_email_out_of_logs(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'reset.me@example.test']);

        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors();

        $otp = DB::table('password_reset_tokens_secure')->where('email', $user->email)->value('verification_code');
        $this->assertNotEmpty($otp);

        Mail::assertQueued(SecurePasswordResetMail::class, fn ($m) => $m->hasTo($user->email) && $m->otp === $otp);

        $log = implode("\n", $this->logged);
        $this->assertStringNotContainsString($otp, $log);
        $this->assertStringNotContainsString($user->email, $log);
    }

    public function test_unknown_email_sends_nothing(): void
    {
        Mail::fake();

        $this->post('/forgot-password', ['email' => 'nobody@example.test'])->assertSessionHas('status');

        Mail::assertNothingQueued();
        Mail::assertNothingSent();
    }

    public function test_the_answer_never_reveals_whether_an_account_exists(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'real.person@example.test']);

        $known = $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()->getSession()->get('status');
        $unknown = $this->post('/forgot-password', ['email' => 'nobody.here@example.test'])->assertSessionHasNoErrors()->getSession()->get('status');
        $this->assertSame($known, $unknown);

        // A mail transport failure answers the same, never with an error that confirms the account.
        Mail::shouldReceive('to')->andThrow(new \RuntimeException('smtp down'));
        $failed = $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()->getSession()->get('status');
        $this->assertSame($known, $failed);
    }

    public function test_production_with_a_log_mailer_never_writes_the_reset_code_anywhere(): void
    {
        Mail::fake();
        config(['mail.default' => 'log']);
        $this->app['env'] = 'production';
        $logged = [];
        Event::listen(MessageLogged::class, function (MessageLogged $e) use (&$logged) {
            $logged[] = $e->message.' '.json_encode($e->context);
        });
        $user = User::factory()->create(['email' => 'prod.reset@example.test']);

        // CSRF is skipped only when the environment is `testing`; this test runs as production.
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->post('/forgot-password', ['email' => $user->email])->assertSessionHasNoErrors()->assertSessionHas('status');

        Mail::assertNothingSent();
        $code = (string) DB::table('password_reset_tokens_secure')->where('email', $user->email)->value('verification_code');
        foreach ($logged as $line) {
            $this->assertStringNotContainsString($user->email, $line);
            if ($code !== '') {
                $this->assertStringNotContainsString($code, $line);
            }
        }
        $this->assertNotEmpty(array_filter($logged, fn ($l) => str_contains($l, 'Password reset mail withheld')));
    }

    public function test_successful_reset_sends_password_changed_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['email' => 'reset.me@example.test']);
        $this->post('/forgot-password', ['email' => $user->email]);

        $row = DB::table('password_reset_tokens_secure')->where('email', $user->email)->first();
        $token = null;
        Mail::assertQueued(SecurePasswordResetMail::class, function ($m) use (&$token) {
            parse_str((string) parse_url($m->resetUrl, PHP_URL_QUERY), $q);
            $token = basename(parse_url($m->resetUrl, PHP_URL_PATH));

            return true;
        });

        $this->post('/reset-password', [
            'token' => $token,
            'email' => $user->email,
            'verification_code' => $row->verification_code,
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
        ])->assertRedirect(route('login'));

        $this->assertTrue(Hash::check(self::STRONG, $user->fresh()->password));
        Mail::assertQueued(PasswordChangedNotificationMail::class, fn ($m) => $m->hasTo($user->email));
    }

    public function test_changing_own_password_sends_password_changed_mail(): void
    {
        Mail::fake();
        $user = User::factory()->create(['password' => Hash::make('old-password-1')]);

        $this->actingAs($user)->put('/account/password', [
            'current_password' => 'old-password-1',
            'password' => self::STRONG,
            'password_confirmation' => self::STRONG,
        ]);

        Mail::assertQueued(PasswordChangedNotificationMail::class, fn ($m) => $m->hasTo($user->email));
    }
}
