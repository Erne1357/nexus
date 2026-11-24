<?php

namespace App\Modules\Core\Health;

use App\Modules\Core\Enums\HealthStatus;

final readonly class CheckResult
{
    /** @param array<string, mixed> $detail */
    public function __construct(public HealthStatus $status, public float $ms, public array $detail = []) {}

    /** @param array<string, mixed> $detail */
    public static function ok(array $detail = []): self
    {
        return new self(HealthStatus::Ok, 0.0, $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function degraded(string $reason, array $detail = []): self
    {
        return new self(HealthStatus::Degraded, 0.0, ['error' => $reason] + $detail);
    }

    /** @param array<string, mixed> $detail */
    public static function fail(string $reason, array $detail = []): self
    {
        return new self(HealthStatus::Fail, 0.0, ['error' => $reason] + $detail);
    }

    public function withMs(float $ms): self
    {
        return new self($this->status, $ms, $this->detail);
    }
}
