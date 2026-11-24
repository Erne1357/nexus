<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\HealthStatus;
use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use App\Modules\Core\Health\Checks\CacheCheck;
use App\Modules\Core\Health\Checks\DatabaseCheck;
use App\Modules\Core\Health\Checks\HeartbeatCheck;
use App\Modules\Core\Health\Checks\MigrationsCheck;
use App\Modules\Core\Health\Checks\QueueCheck;
use App\Modules\Core\Health\Checks\SolverCheck;
use App\Modules\Core\Health\Checks\StorageCheck;
use App\Modules\Core\Health\HealthReport;
use Illuminate\Contracts\Container\Container;
use Throwable;

/**
 * La lectura de `/health` (readiness): corre todos los chequeos y arma el reporte. Un chequeo que lanza una excepción
 * cuenta como `fail` si es crítico y como `degraded` si no. Con el archivo de drenado, responde `draining` sin correr
 * nada. Detalle en docs/superpowers/specs/2026-11-23-despliegue-continuo-design.md §6.1.
 */
final class HealthReportQuery
{
    public function __construct(private readonly Container $container) {}

    public function run(): HealthReport
    {
        $release = (string) config('health.release');

        if (is_file((string) config('health.drain_file'))) {
            return new HealthReport(HealthStatus::Draining, $release);
        }

        $results = [];
        $status = HealthStatus::Ok;

        foreach ($this->checks() as $check) {
            $results[$check->name()] = $result = $this->runCheck($check);

            if ($result->status === HealthStatus::Fail) {
                $status = HealthStatus::Fail;
            } elseif ($result->status !== HealthStatus::Ok && $status === HealthStatus::Ok) {
                $status = HealthStatus::Degraded;
            }
        }

        return new HealthReport($status, $release, $results);
    }

    /** @return list<Check> */
    private function checks(): array
    {
        return [
            $this->container->make(DatabaseCheck::class),
            $this->container->make(CacheCheck::class),
            $this->container->make(QueueCheck::class),
            $this->container->make(StorageCheck::class),
            $this->container->make(MigrationsCheck::class),
            $this->container->make(SolverCheck::class),
            new HeartbeatCheck('queue_worker', HeartbeatCheck::QUEUE_KEY),
            new HeartbeatCheck('scheduler', HeartbeatCheck::SCHEDULER_KEY),
        ];
    }

    private function runCheck(Check $check): CheckResult
    {
        $started = hrtime(true);

        try {
            $result = $check->run();
        } catch (Throwable $e) {
            $result = $check->critical() ? CheckResult::fail($e->getMessage()) : CheckResult::degraded($e->getMessage());
        }

        return $result->withMs((hrtime(true) - $started) / 1e6);
    }
}
