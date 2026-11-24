<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use Illuminate\Support\Facades\DB;

final class DatabaseCheck implements Check
{
    public function name(): string
    {
        return 'database';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        DB::connection(config('health.database_connection'))->select('select 1');

        return CheckResult::ok();
    }
}
