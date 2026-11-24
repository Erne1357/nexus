<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use Illuminate\Support\Facades\Http;

final class SolverCheck implements Check
{
    public function name(): string
    {
        return 'solver';
    }

    public function critical(): bool
    {
        return false;
    }

    public function run(): CheckResult
    {
        $url = trim((string) config('services.solver.url'));

        if ($url === '') {
            return CheckResult::ok(['configured' => false]);
        }

        Http::timeout((float) config('health.solver_timeout'))->get(rtrim($url, '/').'/health')->throw();

        return CheckResult::ok(['configured' => true]);
    }
}
