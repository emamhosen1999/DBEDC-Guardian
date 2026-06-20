<?php

namespace App\Services\Attendance\DTO;

final class PolicyProfile
{
    public function __construct(
        private readonly string $strictness = 'warn',
        private readonly int $outsideWindowMinutes = 120,
        private readonly ?array $graceTiers = null,
        private readonly ?array $rounding = null,
        private readonly ?array $breaks = null,
        private readonly ?array $overtime = null,
    ) {}

    public static function neutral(): self
    {
        return new self();
    }

    public function strictness(): string { return $this->strictness; }
    public function outsideWindowMinutes(): int { return $this->outsideWindowMinutes; }
    public function graceTiers(): ?array { return $this->graceTiers; }
    public function rounding(): ?array { return $this->rounding; }
    public function breaks(): ?array { return $this->breaks; }
    public function overtime(): ?array { return $this->overtime; }

    public function isNeutral(): bool
    {
        return $this->strictness === 'warn'
            && $this->graceTiers === null
            && $this->rounding === null
            && $this->breaks === null
            && $this->overtime === null;
    }
}
