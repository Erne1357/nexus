<?php

use App\Modules\Core\Actions\ForgetFailedJob;
use App\Modules\Core\Actions\RetryFailedJob;
use App\Modules\Core\Models\FailedJob;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Activitylog\Models\Activity;

/*
| RetryFailedJob y ForgetFailedJob (CF-S8): operan sobre `failed_jobs` por uuid con el failer de Laravel y dejan bitácora.
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->uuid = (string) Str::uuid();

    DB::table('failed_jobs')->insert([
        'uuid' => $this->uuid,
        'connection' => 'database',
        'queue' => 'bulk',
        'payload' => json_encode(['uuid' => $this->uuid, 'displayName' => 'App\\Jobs\\Demo', 'job' => 'Illuminate\\Queue\\CallQueuedHandler@call', 'attempts' => 3, 'data' => ['commandName' => 'App\\Jobs\\Demo']]),
        'exception' => "RuntimeException: se cayó\n#0 pila",
        'failed_at' => now(),
    ]);
});

it('CF-S8: reintentar saca el trabajo de los fallidos, lo vuelve a encolar en su cola y deja bitácora', function () {
    app(RetryFailedJob::class)->handle(FailedJob::query()->where('uuid', $this->uuid)->first(), $this->admin);

    $pushed = DB::table('jobs')->where('queue', 'bulk')->first();
    $entry = Activity::query()->where('event', 'failed-job-retried')->sole();

    expect(DB::table('failed_jobs')->where('uuid', $this->uuid)->exists())->toBeFalse()
        ->and($pushed)->not->toBeNull()
        ->and(json_decode($pushed->payload, true)['attempts'])->toBe(0)
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['job'])->toBe('App\\Jobs\\Demo')
        ->and($entry->properties['queue'])->toBe('bulk')
        ->and($entry->properties['error'])->toBe('RuntimeException: se cayó');
});

it('CF-S8: descartar borra el trabajo de los fallidos sin encolarlo y deja bitácora', function () {
    app(ForgetFailedJob::class)->handle(FailedJob::query()->where('uuid', $this->uuid)->first(), $this->admin);

    $entry = Activity::query()->where('event', 'failed-job-forgotten')->sole();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(DB::table('jobs')->count())->toBe(0)
        ->and($entry->causer_id)->toBe($this->admin->id)
        ->and($entry->properties['uuid'])->toBe($this->uuid);
});

it('CF-S8: si el trabajo ya no existe, ninguna de las dos escribe en la bitácora', function () {
    $job = FailedJob::query()->where('uuid', $this->uuid)->first();
    DB::table('failed_jobs')->delete();

    app(RetryFailedJob::class)->handle($job, $this->admin);
    app(ForgetFailedJob::class)->handle($job, $this->admin);

    expect(Activity::query()->whereIn('event', ['failed-job-retried', 'failed-job-forgotten'])->count())->toBe(0);
});

it('CF-S8: si queue:retry no saca el trabajo de los fallidos, lanza y no deja bitácora', function () {
    Artisan::shouldReceive('call')->once()->andReturn(1);

    expect(fn () => app(RetryFailedJob::class)->handle(FailedJob::query()->where('uuid', $this->uuid)->first(), $this->admin))
        ->toThrow(RuntimeException::class);

    expect(Activity::query()->where('event', 'failed-job-retried')->count())->toBe(0)
        ->and(DB::table('failed_jobs')->where('uuid', $this->uuid)->exists())->toBeTrue();
});
