<?php

namespace App\Services\Attendance\DTO;

final class PolicyProfile
{
    public function __construct(
        private readonly string $strictness = 'warn',
        private readonly int $outsideWindowMinutes = 120,
        private readonly ?array $graceTiers = null,
        private readonly ?array $rounding = null,
    ) {}

    public static function neutral(): self
    {
        return new self();
    }

    public function strictness(): string { return $this->strictness; }
    public function outsideWindowMinutes(): int { return $this->outsideWindowMinutes; }
    public function graceTiers(): ?array { return $this->graceTiers; }
    public function rounding(): ?array { return $this->rounding; }

    public function isNeutral(): bool
    {
        return $this->strictness === 'warn' && $this->graceTiers === null && $this->rounding === null;
    }
}
