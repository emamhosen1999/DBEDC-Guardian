<?php

namespace Tests\Feature\Api;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class ApiRateLimitTest extends TestCase
{
    public function test_signed_in_users_get_a_mobile_sized_budget_and_guests_a_per_ip_one(): void
    {
        $user = new User;
        $user->forceFill(['id' => 7, 'employee_id' => '7']);
        $signedIn = Request::create('/api/v1/attendance/today');
        $signedIn->setUserResolver(fn () => $user);
        $guest = Request::create('/api/v1/auth/login', 'POST', server: ['REMOTE_ADDR' => '10.0.0.9']);

        $limiter = RateLimiter::limiter('api');
        $this->assertSame(180, $limiter($signedIn)->maxAttempts);
        $this->assertSame('user:7', $limiter($signedIn)->key);
        $this->assertSame(120, $limiter($guest)->maxAttempts);
        $this->assertSame('ip:10.0.0.9', $limiter($guest)->key);
    }

    public function test_attachment_image_views_use_their_own_budget_not_the_general_api_one(): void
    {
        foreach (['api.v1.attachments.staged.image', 'api.v1.attachments.show'] as $name) {
            $middleware = Route::getRoutes()->getByName($name)->gatherMiddleware();
            $this->assertContains('throttle:attachment-views', $middleware, $name);
            $this->assertNotContains('throttle:api', array_values(array_diff($middleware, Route::getRoutes()->getByName($name)->excludedMiddleware())), $name);
        }
        $this->assertSame(300, (RateLimiter::limiter('attachment-views'))(Request::create('/x'))->maxAttempts);
    }
}
