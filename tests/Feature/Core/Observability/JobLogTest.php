<?php

use App\Modules\Core\Observability\CanonicalLog;
use App\Modules\Core\Observability\OperationMetrics;
use Illuminate\Contracts\Queue\Job;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Log;

/*
| Fase 2b: cada job de la cola deja una línea `job.done` o `job.failed` con la lista blanca JOB_KEYS. La cola de las
| pruebas es `sync` (phpunit.xml), y la conexión `sync` no escribe línea (corre dentro de quien la llamó): por eso estas
| pruebas definen una conexión con driver `sync` y otro nombre, que sí cuenta como cola de worker.
*/

beforeEach(function () {
    config(['queue.connections.inline' => ['driver' => 'sync']]);
});

class JobLogProbe implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        DB::select('select 1');
    }
}

class JobLogBoom implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        throw new RuntimeException('boom');
    }
}

class JobLogReleases implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public function handle(): void
    {
        $this->release(10);
    }
}

class JobLogSlow implements ShouldQueue
{
    use Dispatchable, Queueable;

    public function handle(): void
    {
        // Retrocede el inicio del acumulador en vez de dormir 1 s.
        $started = new ReflectionProperty(OperationMetrics::class, 'startedAt');
        $started->setValue(app(OperationMetrics::class), hrtime(true) - 2_000_000_000);
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

    JobLogProbe::dispatch()->onConnection('inline');

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

    expect(fn () => JobLogBoom::dispatch()->onConnection('inline'))->toThrow(RuntimeException::class);

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message'])->toBe('job.failed')
        ->and($calls[0]['level'])->toBe('error')
        ->and($calls[0]['context']['outcome'])->toBe('failed');
});

it('un job lento sigue saliendo en info: la regla de 1 s es de las peticiones', function () {
    $calls = jobLines();

    JobLogSlow::dispatch()->onConnection('inline');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message'])->toBe('job.done')
        ->and($calls[0]['level'])->toBe('info')
        ->and($calls[0]['context']['duration_ms'])->toBeGreaterThan(1000);
});

it('un job que se libera deja job.released en info y no job.done', function () {
    $calls = jobLines();

    JobLogReleases::dispatch()->onConnection('inline');

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message'])->toBe('job.released')
        ->and($calls[0]['level'])->toBe('info')
        ->and($calls[0]['context']['outcome'])->toBe('released');
});

it('un intento que lanza con reintentos pendientes deja job.retry en warning', function () {
    $calls = jobLines();
    // La conexión sync no reintenta (falla de inmediato): se dispara a mano el evento que lanza el worker.
    $job = Mockery::mock(Job::class);
    $job->allows(['payload' => [], 'resolveName' => JobLogBoom::class, 'getQueue' => 'default', 'attempts' => 1]);

    Event::dispatch(new JobReleasedAfterException('redis', $job, 10, new RuntimeException('boom')));

    expect($calls)->toHaveCount(1)
        ->and($calls[0]['message'])->toBe('job.retry')
        ->and($calls[0]['level'])->toBe('warning')
        ->and($calls[0]['context']['outcome'])->toBe('retry')
        ->and(array_keys($calls[0]['context']))->toBe(CanonicalLog::JOB_KEYS);
});

it('un job por la conexión sync corre dentro de quien lo llama y no escribe línea propia', function () {
    $calls = jobLines();

    JobLogProbe::dispatch();
    expect(fn () => JobLogBoom::dispatch())->toThrow(RuntimeException::class);

    expect($calls)->toBeEmpty();
});
