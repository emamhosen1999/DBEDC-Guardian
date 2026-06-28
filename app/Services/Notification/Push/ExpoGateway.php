<?php
// app/Services/Notification/Push/ExpoGateway.php
namespace App\Services\Notification\Push;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class ExpoGateway implements PushGateway
{
    private const ENDPOINT = 'https://exp.host/--/api/v2/push/send';

    public function send(iterable $tokens, PushMessage $message): array
    {
        $tokenStrings = collect($tokens)->pluck('token')->filter()->values();
        if ($tokenStrings->isEmpty()) {
            return [];
        }

        $payload = $tokenStrings->map(fn ($t) => [
            'to' => $t,
            'title' => $message->title,
            'body' => $message->body,
            'data' => $message->data,
            'sound' => 'default',
        ])->all();

        $invalid = [];
        try {
            $response = Http::acceptJson()->asJson()->post(self::ENDPOINT, $payload);
            $tickets = $response->json('data', []);
            foreach ($tickets as $i => $ticket) {
                if (($ticket['status'] ?? null) === 'error'
                    && ($ticket['details']['error'] ?? null) === 'DeviceNotRegistered') {
                    $invalid[] = $tokenStrings[$i];
                }
            }
        } catch (\Throwable $e) {
            Log::error('Expo push failed', ['error' => $e->getMessage()]);
        }

        return $invalid;
    }
}
