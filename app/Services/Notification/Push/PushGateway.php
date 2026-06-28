<?php
// app/Services/Notification/Push/PushGateway.php
namespace App\Services\Notification\Push;

interface PushGateway
{
    /**
     * @param  iterable<\App\Models\NotificationToken>  $tokens
     * @return string[]  tokens that are permanently invalid and should be pruned
     */
    public function send(iterable $tokens, PushMessage $message): array;
}
