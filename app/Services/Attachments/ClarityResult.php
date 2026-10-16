<?php

namespace App\Services\Attachments;

/** Verdict of the clarity check: pass/fail, a stable code, an actionable (translatable) message and the measured values. */
final class ClarityResult
{
    /** @param array<string, mixed> $metrics */
    public function __construct(
        public readonly bool $passed,
        public readonly string $code,
        public readonly string $message,
        public readonly array $metrics = [],
    ) {}

    /** @param array<string, mixed> $metrics */
    public static function pass(array $metrics = []): self
    {
        return new self(true, 'ok', __('attachments.clarity.ok'), $metrics);
    }

    /** @param array<string, mixed> $metrics */
    public static function fail(string $code, array $metrics = []): self
    {
        return new self(false, $code, __('attachments.clarity.'.$code), $metrics);
    }

    /** @return array{passed: bool, code: string, message: string, metrics: array<string, mixed>} */
    public function toArray(): array
    {
        return ['passed' => $this->passed, 'code' => $this->code, 'message' => $this->message, 'metrics' => $this->metrics];
    }
}
