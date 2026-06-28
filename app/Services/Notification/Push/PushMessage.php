<?php
// app/Services/Notification/Push/PushMessage.php
namespace App\Services\Notification\Push;

class PushMessage
{
    public function __construct(
        public readonly string $title,
        public readonly string $body,
        public readonly array $data = [],
    ) {}
}
