<?php
// app/Services/Notification/Push/FcmGateway.php
namespace App\Services\Notification\Push;

use App\Services\Notification\FcmNotificationService;

class FcmGateway implements PushGateway
{
    public function __construct(private FcmNotificationService $fcm) {}

    public function send(iterable $tokens, PushMessage $message): array
    {
        $tokenStrings = collect($tokens)->pluck('token')->filter()->values()->all();
        if (empty($tokenStrings)) {
            return [];
        }

        // Existing service casts data values to string for FCM.
        $data = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $message->data);

        $report = $this->fcm->sendMulticastNotification($tokenStrings, $message->title, $message->body, $data);

        return $report['invalid_tokens'] ?? [];
    }
}
