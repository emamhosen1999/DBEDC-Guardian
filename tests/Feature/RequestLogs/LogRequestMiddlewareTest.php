<?php

namespace Tests\Feature\RequestLogs;

use App\Http\Middleware\LogRequestMiddleware;
use App\Models\RequestLog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class LogRequestMiddlewareTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        RequestLog::flushEventListeners();
        parent::tearDown();
    }

    public function test_row_is_written_in_terminate_after_the_response_not_before(): void
    {
        $middleware = new LogRequestMiddleware;
        $request = Request::create('/some/page', 'GET');
        $response = $middleware->handle($request, fn () => response('ok', 200));

        $this->assertSame(0, RequestLog::count(), 'handle() must not write synchronously');

        $middleware->terminate($request, $response);

        $this->assertSame(1, RequestLog::count());
        $this->assertSame(200, RequestLog::first()->response_status);
    }

    public function test_secrets_are_redacted_in_headers_query_and_body(): void
    {
        $request = Request::create('/hr/profile?token=abc123&page=2', 'POST', [
            'name' => 'Rahim',
            'password' => 'hunter2',
            'verification_code' => '482913',
            'nested' => ['otp' => '1234', 'bank_account_number' => '9988', 'nid' => '1990123456789', 'city' => 'Dhaka'],
        ], [], [], ['HTTP_AUTHORIZATION' => 'Bearer SECRET-TOKEN', 'HTTP_COOKIE' => 'laravel_session=SESS', 'HTTP_X_XSRF_TOKEN' => 'XSRF']);

        $middleware = new LogRequestMiddleware;
        $middleware->terminate($request, $middleware->handle($request, fn () => response('ok')));

        $log = RequestLog::firstOrFail();
        $stored = json_encode($log->toArray());

        foreach (['hunter2', '482913', 'abc123', '1234', '9988', '1990123456789', 'SECRET-TOKEN', 'SESS', 'XSRF'] as $secret) {
            $this->assertStringNotContainsString($secret, $stored, "{$secret} leaked into request_logs");
        }
        $this->assertSame('Rahim', $log->request_body['name']);
        $this->assertSame('Dhaka', $log->request_body['nested']['city']);
        $this->assertStringContainsString('page=2', $log->url);
    }

    public function test_request_and_response_bodies_are_capped(): void
    {
        config(['request-logs.max_request_bytes' => 100, 'request-logs.max_response_bytes' => 50]);

        $request = Request::create('/x', 'POST', ['blob' => str_repeat('a', 500)]);
        $middleware = new LogRequestMiddleware;
        $middleware->terminate($request, $middleware->handle($request, fn () => response(str_repeat('é', 200), 500)));

        $log = RequestLog::firstOrFail();
        $this->assertSame(['_truncated' => true], $log->request_body);
        $this->assertStringEndsWith('... [truncated]', $log->response_body);
        $this->assertLessThanOrEqual(50 + 15, strlen($log->response_body));
        $this->assertTrue(mb_check_encoding($log->response_body, 'UTF-8'));
    }

    public function test_successful_response_bodies_are_not_stored(): void
    {
        $request = Request::create('/some/page', 'GET');
        $middleware = new LogRequestMiddleware;
        $middleware->terminate($request, $middleware->handle($request, fn () => response('<html>big page</html>', 200)));

        $this->assertNull(RequestLog::firstOrFail()->response_body);
    }

    public function test_excluded_routes_and_static_files_are_skipped(): void
    {
        foreach (['/up', '/health', '/build/assets/app.js', '/notifications/unread-count', '/api/v1/heartbeat', '/storage/x/photo.png', '/logo.svg', '/csrf-token'] as $path) {
            $request = Request::create($path, 'GET');
            $middleware = new LogRequestMiddleware;
            $middleware->terminate($request, $middleware->handle($request, fn () => response('ok')));
        }

        $this->assertSame(0, RequestLog::count());
    }

    public function test_exclusion_list_is_configurable_and_logging_can_be_disabled(): void
    {
        config(['request-logs.exclude' => ['custom/noise*']]);
        $request = Request::create('/custom/noise/1', 'GET');
        $middleware = new LogRequestMiddleware;
        $middleware->terminate($request, $middleware->handle($request, fn () => response('ok')));
        $this->assertSame(0, RequestLog::count());

        config(['request-logs.enabled' => false]);
        $request = Request::create('/normal', 'GET');
        $middleware->terminate($request, $middleware->handle($request, fn () => response('ok')));
        $this->assertSame(0, RequestLog::count());
    }

    public function test_a_logging_failure_never_breaks_the_request(): void
    {
        RequestLog::creating(function () {
            throw new RuntimeException('disk full');
        });

        Route::get('/_probe-ok', fn () => response('fine'));

        $this->get('/_probe-ok')->assertOk()->assertSee('fine');
        $this->assertSame(0, RequestLog::count());
    }

    public function test_a_real_request_is_logged_through_the_global_stack(): void
    {
        Route::post('/_probe-login', fn () => response('nope', 422));

        $this->post('/_probe-login', ['email' => 'a@b.c', 'password' => 'secret-pass'])->assertStatus(422);

        $log = RequestLog::where('url', 'like', '%_probe-login')->firstOrFail();
        $this->assertSame('POST', $log->method);
        $this->assertSame('[REDACTED]', $log->request_body['password']);
    }
}
