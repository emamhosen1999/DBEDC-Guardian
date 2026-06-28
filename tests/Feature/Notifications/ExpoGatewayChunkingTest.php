<?php
// tests/Feature/Notifications/ExpoGatewayChunkingTest.php
namespace Tests\Feature\Notifications;

use App\Services\Notification\Push\ExpoGateway;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ExpoGatewayChunkingTest extends TestCase
{
    public function test_sends_in_chunks_of_100_and_maps_invalid_tokens_per_chunk(): void
    {
        // 150 tokens → 2 requests (100 + 50). Make the 1st token of the 2nd chunk
        // (index 100 overall) report DeviceNotRegistered.
        Http::fakeSequence('exp.host/*')
            ->push(['data' => array_fill(0, 100, ['status' => 'ok'])])
            ->push(['data' => array_merge(
                [['status' => 'error', 'message' => 'x', 'details' => ['error' => 'DeviceNotRegistered']]],
                array_fill(0, 49, ['status' => 'ok']),
            )]);

        $tokens = collect(range(1, 150))->map(fn ($i) => (object) ['token' => "ExponentPushToken[$i]", 'provider' => 'expo']);

        $invalid = app(ExpoGateway::class)->send($tokens, new PushMessage('Hi', 'Body', ['type_key' => 'attendance.roster_changed']));

        Http::assertSentCount(2);
        // The DeviceNotRegistered ticket sat at the start of chunk 2 → token #101.
        $this->assertSame(['ExponentPushToken[101]'], $invalid);
    }
}
