<?php

use App\Modules\Core\Observability\CanonicalLog;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/*
| Fase 2b: cada job de la cola deja una línea `job.done` o `job.failed` con la lista blanca JOB_KEYS. La cola de las
| pruebas es `sync` (phpunit.xml): el job corre dentro de la petición, igual que en dev.
*/

class JobLogProbe implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        DB::select('select 1');
    }
}

class JobLogBoom implements ShouldQueue
{
    use Dispatchable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

function jobLines(): ArrayObject
{
    $calls = new ArrayObject;
    Log::spy()->shouldReceive('log')->andReturnUsing(function (string $level, string $message, array $context = []) use ($calls): void {
        if (str_starts_with($message, 'job.')) {
            $calls[] = compact('level', 'message', 'context');
        }
    });

    return $calls;
}

it('escribe job.done con la lista blanca y el request_id del contexto', function () {
    $calls = jobLines();
    Context::add('request_id', 'r-123');
    // Lo que heredaría del request que lo despachó: el módulo del job (su clase) debe ganarle.
    Context::add('module', 'staffing');

    JobLogProbe::dispatch();

    expect($calls)->toHaveCount(1);
    $line = $calls[0];
    expect($line['message'])->toBe('job.done')
        ->and($line['level'])->toBe('info')
        ->and(array_keys($line['context']))->toBe(CanonicalLog::JOB_KEYS)
        ->and($line['context']['request_id'])->toBe('r-123')
        ->and($line['context']['job'])->toBe(JobLogProbe::class)
        ->and($line['context']['attempt'])->toBe(1)
        ->and($line['context']['outcome'])->toBe('done')
        ->and($line['context']['module'])->toBe('core')
        ->and($line['context']['db_ms'])->toBeGreaterThanOrEqual(0);
});

it('escribe job.failed como error cuando el job falla', function () {
    $calls = jobLines();

    expect(fn () => JobLogBoom::dispatch())->toThrow(RuntimeException::class);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message'])->toBe('job.failed')
        ->and($calls[0]['level'])->toBe('error')
        ->and($calls[0]['context']['outcome'])->toBe('failed');
});
