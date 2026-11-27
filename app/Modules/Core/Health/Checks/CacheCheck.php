<?php

namespace App\Modules\Core\Health\Checks;

use App\Modules\Core\Health\Check;
use App\Modules\Core\Health\CheckResult;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

final class CacheCheck implements Check
{
    public function name(): string
    {
        return 'cache';
    }

    public function critical(): bool
    {
        return true;
    }

    public function run(): CheckResult
    {
        $store = Cache::store(config('health.cache_store'));
        $key = 'health:probe:'.Str::random(16);
        $value = Str::random(8);

        $store->put($key, $value, 10);
        $read = $store->get($key);
        $store->forget($key);

        return $read === $value ? CheckResult::ok() : CheckResult::fail('La caché no devolvió lo escrito.');
    }
}
