<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Services\Notification\FcmNotificationService;
use App\Services\Notification\Push\ExpoGateway;
use App\Services\Notification\Push\PushMessage;
use Illuminate\Console\Command;

class PushTestCommand extends Command
{
    protected $signature = 'push:test
        {userId : The id of the user to push to}
        {--title=Test push : Notification title}
        {--body=If you can read this, web push works. : Notification body}
        {--url=/mobile/leaves : Click-through url delivered in the data payload}
        {--web-only : Only send to tokens whose platform is web}';

    protected $description = 'Send a real FCM push to a user\'s stored notification tokens (verifies the web/PWA push path end-to-end).';

    public function handle(FcmNotificationService $fcm, ExpoGateway $expo): int
    {
        $user = User::find($this->argument('userId'));
        if (! $user) {
            $this->error("User #{$this->argument('userId')} not found.");

            return self::FAILURE;
        }

        $title = (string) $this->option('title');
        $body = (string) $this->option('body');
        $url = (string) $this->option('url');

        $query = $user->notificationTokens();
        if ($this->option('web-only')) {
            $query->where('platform', 'web');
        }
        $tokens = $query->get();

        if ($tokens->isEmpty()) {
            $this->warn("User #{$user->id} ({$user->name}) has no matching notification tokens.");

            return self::SUCCESS;
        }

        // Same payload shape the notifications' toPush() produces.
        $message = new PushMessage($title, $body, [
            'type_key' => 'push.test',
            'url' => $url,
        ]);
        // FCM requires all data values to be strings (mirrors FcmGateway).
        $data = array_map(fn ($v) => is_scalar($v) ? (string) $v : json_encode($v), $message->data);

        $this->info("Sending to {$tokens->count()} token(s) for user #{$user->id} ({$user->name}):");
        $this->newLine();

        $anySuccess = false;

        foreach ($tokens as $token) {
            $preview = mb_substr($token->token, 0, 18).'...';
            $label = "[{$token->platform}/{$token->provider}] {$preview}";

            if ($token->provider === 'expo') {
                // Route Expo tokens through the real Expo gateway.
                $invalid = $expo->send(collect([$token]), $message);
                $ok = empty($invalid);
            } else {
                // Real FCM send path (same service + CloudMessage builder the
                // notification multicast uses; includes the notification block
                // title/body that a web service worker displays).
                $ok = $fcm->sendNotification($token->token, $title, $body, $data);
            }

            $anySuccess = $anySuccess || $ok;
            $ok
                ? $this->line("  <fg=green>OK  </> {$label}")
                : $this->line("  <fg=red>FAIL</> {$label}  (see laravel.log for the FCM error)");
        }

        $this->newLine();
        $this->line('Done. Detailed FCM errors (invalid token, credential/config issues) are written to the log.');

        return $anySuccess ? self::SUCCESS : self::FAILURE;
    }
}
