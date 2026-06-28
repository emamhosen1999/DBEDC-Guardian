<?php

namespace Tests\Feature\Realtime;

use App\Services\Realtime\RealtimeSignal;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Middleware;
use GuzzleHttp\Psr7\Response;
use Kreait\Firebase\Contract\Database;
use Kreait\Firebase\Database\ApiClient;
use Kreait\Firebase\Database\Reference;
use Kreait\Firebase\Database\UrlBuilder;
use Kreait\Firebase\Exception\DatabaseApiExceptionConverter;
use Kreait\Firebase\Http\ErrorResponseParser;
use Mockery;
use Tests\TestCase;

class RealtimeSignalTest extends TestCase
{
    /**
     * Kreait's Database\Reference and ApiClient are `final readonly` classes, so they cannot
     * be mocked directly with Mockery (it cannot subclass or decorate a final class behind a
     * type-hinted return). Instead we wire a real Reference to a real ApiClient whose HTTP
     * transport is a Guzzle MockHandler, and assert on the actual outgoing HTTP request/body.
     * Only the `Database` contract (an interface) is Mockery-mocked.
     *
     * @param array<int, array{request: \Psr\Http\Message\RequestInterface}> $history captured by reference
     */
    private function realReferenceWithCapturedRequests(string $path, array &$history): Reference
    {
        $history = [];
        $mockHandler = new MockHandler([new Response(200, [], json_encode(['ok' => true]))]);
        $stack = HandlerStack::create($mockHandler);
        $stack->push(Middleware::history($history));

        $urlBuilder = UrlBuilder::create('https://testns-default-rtdb.firebaseio.com');
        $apiClient = new ApiClient(
            new Client(['handler' => $stack]),
            $urlBuilder,
            new DatabaseApiExceptionConverter(new ErrorResponseParser()),
        );

        $uri = \GuzzleHttp\Psr7\Utils::uriFor($urlBuilder->getUrl($path));

        return new Reference($uri, $apiClient);
    }

    public function test_touch_writes_marker_to_namespaced_path(): void
    {
        config(['realtime.enabled' => true, 'realtime.namespace' => 'testns']);

        $history = [];
        $reference = $this->realReferenceWithCapturedRequests('signals/testns/roster/2026-06', $history);

        $db = Mockery::mock(Database::class);
        $db->shouldReceive('getReference')->once()
            ->with('signals/testns/roster/2026-06')
            ->andReturn($reference);
        $this->app->instance(Database::class, $db);

        app(RealtimeSignal::class)->touch('roster', '2026-06', 7);

        $this->assertCount(1, $history);
        $request = $history[0]['request'];
        $this->assertSame('PUT', $request->getMethod());
        $this->assertStringContainsString('signals/testns/roster/2026-06', (string) $request->getUri());

        $payload = json_decode((string) $request->getBody(), true);
        $this->assertSame(7, $payload['actor_id']);
        $this->assertSame('update', $payload['action']);
        $this->assertArrayHasKey('ts', $payload);
    }

    public function test_touch_is_noop_when_disabled(): void
    {
        config(['realtime.enabled' => false]);

        $db = Mockery::mock(Database::class);
        $db->shouldNotReceive('getReference');
        $this->app->instance(Database::class, $db);

        app(RealtimeSignal::class)->touch('roster', '2026-06', 7); // must not resolve/use Database
        $this->assertTrue(true);
    }

    public function test_touch_never_throws_when_publish_fails(): void
    {
        config(['realtime.enabled' => true, 'realtime.namespace' => 'testns']);
        $db = Mockery::mock(Database::class);
        $db->shouldReceive('getReference')->andThrow(new \RuntimeException('rtdb down'));
        $this->app->instance(Database::class, $db);

        app(RealtimeSignal::class)->touch('roster', '2026-06', 7); // must not throw
        $this->assertTrue(true);
    }

    protected function tearDown(): void
    {
        Mockery::close();
        parent::tearDown();
    }
}
