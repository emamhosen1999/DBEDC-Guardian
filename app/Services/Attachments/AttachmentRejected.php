<?php

namespace App\Services\Attachments;

use Illuminate\Http\JsonResponse;
use RuntimeException;

/** A document that cannot be accepted. The message is already actionable and translated; the controller returns it with HTTP 422. */
class AttachmentRejected extends RuntimeException
{
    /** @param array<string, mixed>|null $clarity */
    public function __construct(string $message, public readonly string $reason = 'rejected', public readonly ?array $clarity = null)
    {
        parent::__construct($message, 422);
    }

    /** Rendered like a validation error (422) so every client handles it the same way. */
    public function render($request): JsonResponse
    {
        return response()->json($this->toArray() + ['errors' => ['attachments' => [$this->getMessage()]]], 422);
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return ['success' => false, 'message' => $this->getMessage(), 'code' => $this->reason, 'clarity' => $this->clarity];
    }
}
