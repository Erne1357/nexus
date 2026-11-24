<?php

namespace App\Modules\Core\Health;

/**
 * Un chequeo de `/health`. Puede lanzar una excepción: HealthReportQuery la cuenta como `fail` si es crítico y como
 * `degraded` si no.
 */
interface Check
{
    public function name(): string;

    /** Si falla, todo `/health` falla (503). */
    public function critical(): bool;

    public function run(): CheckResult;
}
