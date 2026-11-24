<?php

namespace App\Modules\Core\Health;

use App\Modules\Core\Enums\HealthStatus;

final readonly class HealthReport
{
    /** @param array<string, CheckResult> $checks */
    public function __construct(public HealthStatus $status, public string $release, public array $checks = [], public bool $draining = false) {}

    /** 200 si sirve (`ok`, `degraded`); 503 si no (`draining`, `fail`). */
    public function httpStatus(): int
    {
        return match ($this->status) {
            HealthStatus::Ok, HealthStatus::Degraded => 200,
            HealthStatus::Draining, HealthStatus::Fail => 503,
        };
    }

    /** @return array<string, mixed> */
    public function toArray(bool $withDetail): array
    {
        $report = ['status' => $this->status->value, 'release' => $this->release];

        if ($this->status === HealthStatus::Draining) {
            return $report;
        }

        if ($this->draining) {
            $report['draining'] = true;
        }

        $report['checks'] = array_map(function (CheckResult $result) use ($withDetail): array {
            $entry = ['status' => $result->status->value, 'ms' => round($result->ms, 1)];

            if ($withDetail) {
                $entry['detail'] = $result->detail;
            }

            return $entry;
        }, $this->checks);

        return $report;
    }
}
