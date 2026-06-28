<?php
// app/Services/Notification/Push/PushDispatcher.php
namespace App\Services\Notification\Push;

use App\Models\NotificationToken;
use Illuminate\Support\Collection;

class PushDispatcher
{
    public function __construct(private FcmGateway $fcm, private ExpoGateway $expo) {}

    /**
     * @param  iterable<NotificationToken>  $tokens
     */
    public function send(iterable $tokens, PushMessage $message): void
    {
        $byProvider = (new Collection($tokens))->groupBy('provider');

        $invalid = [];
        if ($byProvider->has('fcm')) {
            $invalid = array_merge($invalid, $this->fcm->send($byProvider->get('fcm'), $message));
        }
        if ($byProvider->has('expo')) {
            $invalid = array_merge($invalid, $this->expo->send($byProvider->get('expo'), $message));
        }

        if (! empty($invalid)) {
            NotificationToken::whereIn('token', $invalid)->delete();
        }
    }
}
