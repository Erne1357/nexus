<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use Illuminate\Support\Str;

final class StorageCheck implements Check
{
    public function name(): string
    {
        return 'storage';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $path = storage_path('app/.health-'.Str::random(8));

        if (@file_put_contents($path, 'ok') === false) {
            return CheckResult::fail('No se puede escribir en storage/app.');
        }

        @unlink($path);

        return CheckResult::ok();
    }
}
