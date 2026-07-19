<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Locks in the single, intentional session/token policy shared by web and mobile.
 * See docs/session-expiry-policy.md.
 */
class SessionTimeoutPolicyTest extends TestCase
{
    use RefreshDatabase;

    public function test_idle_timeout_is_a_single_source_of_truth(): void
    {
        // SESSION_LIFETIME is THE idle window for both web sessions and mobile
        // tokens. Default (no env) must be the documented 8 hours.
        $this->assertSame(480, (int) config('session.lifetime'));
    }

    public function test_browser_close_does_not_force_logout_by_default(): void
    {
        // expire_on_close must default to false so closing the browser/tab does
        // not produce an unpredictable logout that web users see but mobile never does.
        $this->assertFalse(config('session.expire_on_close'));
    }

    public function test_same_site_default_is_lax_not_strict(): void
    {
        // 'strict' drops the session cookie on cross-site top-level navigations
        // (links from email, OAuth redirects) -> spurious "logged out". 'lax' is
        // the correct default for first-party web auth.
        $this->assertSame('lax', config('session.same_site'));
    }

    public function test_sanctum_global_absolute_expiration_is_the_30_day_backstop(): void
    {
        // Idle parity for mobile is still enforced via per-token sliding expires_at.
        // Previously this global value was null (never-expire), but security commit
        // fca11c068 (AUDIT-02 2.3) added a 30-day ABSOLUTE-from-creation backstop so a
        // stolen-but-kept-alive token dies at most 30 days after issue. Sanctum ANDs the
        // global expiration with each token's sliding expires_at, so the 8h idle window
        // is unchanged — net policy is min(8h idle, 30d absolute). This test was updated
        // from asserting null to lock in that deliberate 30-day cap (43200 minutes).
        $this->assertSame(43200, (int) config('sanctum.expiration'));
    }

    public function test_unauthenticated_web_request_returns_same_401_shape_as_mobile(): void
    {
        // The web client treats 401/419 identically (redirect to login). An
        // expired/absent session on an Inertia/XHR request must yield the SAME
        // 401 + redirect contract that an expired mobile token yields.
        $this->withHeader('X-Inertia', 'true')
            ->withHeader('X-Requested-With', 'XMLHttpRequest')
            ->getJson('/dashboard')
            ->assertStatus(401)
            ->assertJsonPath('error_code', 'AUTHENTICATION_REQUIRED')
            ->assertJsonPath('redirect', route('login'));
    }
}
