<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\CancelSolverRun;
use App\Modules\Solver\Contract\RunMetrics;
use App\Modules\Solver\Contract\RunStatus;
use App\Modules\Solver\Contract\SoftScore;
use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Engines\EngineRegistry;
use App\Modules\Solver\Engines\FixedEngine;
use App\Modules\Solver\Engines\SolverEngine;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Jobs\RunSolver;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Notifications\SolverRunFinished;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Route;

/*
| RunSolver (tarea 6.8): toma una corrida en cola, la pasa a corriendo y llama al motor; guarda la salida y las métricas
| y la deja terminada (también con grupos sin acomodar: la pantalla decide qué mostrar) o fallida con el mensaje, sin la
| traza, y avisa en la campana a quien la lanzó. Mientras corre guarda el avance y el mejor puntaje, a lo más una vez
| por segundo, y si se pidió cancelarla (SV-03) la detiene en el siguiente aviso de avance.
*/

beforeEach(function () {
    Notification::fake();
});

/**
 * Una corrida en cola con la entrada `tiny` de los fixtures y su identificador.
 *
 * @param  array<string, mixed>  $attributes
 */
function queuedSolverRun(string $engine = FixedEngine::KEY, array $attributes = []): SolverRun
{
    $run = SolverRun::factory()->create(['engine' => $engine, ...$attributes]);
    $input = json_decode(file_get_contents(__DIR__.'/../../Fixtures/solver/v1/tiny/input.json'), true, flags: JSON_THROW_ON_ERROR);
    $input['run_id'] = $run->id;
    $run->update(['input' => $input]);

    return $run;
}

/**
 * Registra, solo en esta prueba, un motor que hace lo que diga `$script` y, si no devuelve nada, lo del motor de prueba.
 *
 * @param  Closure(SolverInput, ?Closure): ?SolverOutput  $script
 */
function scriptedSolverEngine(string $key, Closure $script): void
{
    $engine = new class($key, $script) implements SolverEngine
    {
        public function __construct(private readonly string $engineKey, private readonly Closure $script) {}

        public function key(): string
        {
            return $this->engineKey;
        }

        public function label(): string
        {
            return 'Motor de la prueba';
        }

        public function supportedVersions(): array
        {
            return [SolverInput::VERSION];
        }

        public function checksHardRules(): bool
        {
            return false;
        }

        public function solve(SolverInput $input, ?Closure $progress = null): SolverOutput
        {
            return ($this->script)($input, $progress) ?? (new FixedEngine)->solve($input);
        }
    };

    app()->instance(EngineRegistry::class, new EngineRegistry([new FixedEngine, $engine]));
}

/**
 * @return array{progress: int, best_score: ?float, status: string}
 */
function solverRunSnapshot(string $runId): array
{
    $run = SolverRun::query()->findOrFail($runId);

    return ['progress' => $run->progress, 'best_score' => $run->best_score, 'status' => $run->status->value];
}

it('lleva la corrida de en cola a terminada con la salida del motor de prueba, sus métricas y el aviso', function () {
    $run = queuedSolverRun();
    $expected = (new FixedEngine)->solve(SolverInput::fromArray($run->input));

    RunSolver::dispatchSync($run->id);
    $run->refresh();

    expect($run)
        ->status->toBe(SolverRunStatus::Done)
        ->progress->toBe(100)
        ->best_score->toBe(0.0)
        ->error->toBeNull()
        ->output_path->toBeNull()
        ->started_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->and($run->output['status'])->toBe(RunStatus::Partial->value)
        ->and($run->output['run_id'])->toBe($run->id)
        ->and($run->output['sessions'])->toEqual(array_map(fn ($session): array => $session->toArray(), $expected->sessions))
        ->and($run->metrics)->toMatchArray([
            'engine' => FixedEngine::KEY,
            'engine_version' => FixedEngine::VERSION,
            'result' => RunStatus::Partial->value,
            'sessions' => count($expected->sessions),
            'changed_sessions' => count($expected->sessions),
            'unscheduled' => count($expected->unscheduled),
            'hard_violations' => 0,
            'soft_score' => 0,
        ]);

    Notification::assertSentTo($run->creator, SolverRunFinished::class, function (SolverRunFinished $notification) use ($run): bool {
        $message = $notification->message($run->creator);

        return $message->title === "La corrida del motor para {$run->department->name} está lista"
            && $message->route === 'solver.runs'
            && $message->parameters === ['corrida' => $run->id]
            && $message->termId === $run->term_id
            && $message->modalityId === $run->modality_id
            && $message->tone === 'success';
    });
});

it('queda fallida con el mensaje del error, sin la traza, y avisa a quien la lanzó', function () {
    scriptedSolverEngine('roto', fn (): never => throw new RuntimeException('El motor se cayó.'));
    $run = queuedSolverRun('roto');

    expect(fn () => RunSolver::dispatchSync($run->id))->toThrow(RuntimeException::class, 'El motor se cayó.')
        ->and($run->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('El motor se cayó.')
        ->output->toBeNull()
        ->finished_at->not->toBeNull();

    Notification::assertSentTo($run->creator, SolverRunFinished::class, function (SolverRunFinished $notification) use ($run): bool {
        $message = $notification->message($run->creator);

        return $message->title === "La corrida del motor para {$run->department->name} no terminó"
            && $message->body === 'El motor se cayó.'
            && $message->tone === 'warning';
    });
});

it('un motor que responde con estado de error deja la corrida fallida con su salida y su mensaje', function () {
    scriptedSolverEngine('sin-version', fn (SolverInput $input): SolverOutput => new SolverOutput(
        SolverInput::VERSION, $input->runId, RunStatus::Error, [], [], [], [], new SoftScore(0, []), [],
        new RunMetrics(0.0, 'sin-version', '1.0'), ['La entrada no se pudo leer.'],
    ));
    $run = queuedSolverRun('sin-version');

    RunSolver::dispatchSync($run->id);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Failed)
        ->error->toBe('La entrada no se pudo leer.')
        ->and($run->fresh()->output['status'])->toBe(RunStatus::Error->value);

    Notification::assertSentTo($run->creator, SolverRunFinished::class);
});

it('guarda el avance y el mejor puntaje mientras corre, a lo más una vez por segundo', function () {
    $seen = [];
    $run = queuedSolverRun('pasos');

    scriptedSolverEngine('pasos', function (SolverInput $input, ?Closure $progress) use (&$seen, $run): ?SolverOutput {
        $progress(30, 900);
        $seen[] = solverRunSnapshot($run->id);
        $progress(60, 800);
        $seen[] = solverRunSnapshot($run->id);
        $this->travel(2)->seconds();
        $progress(150, null);
        $seen[] = solverRunSnapshot($run->id);

        return null;
    });

    RunSolver::dispatchSync($run->id);

    expect($seen)->toBe([
        ['progress' => 30, 'best_score' => 900.0, 'status' => 'running'],
        ['progress' => 30, 'best_score' => 900.0, 'status' => 'running'],
        ['progress' => 100, 'best_score' => 900.0, 'status' => 'running'],
    ])->and($run->fresh()->status)->toBe(SolverRunStatus::Done);
});

it('SV-03: una corrida que corre se cancela en el siguiente aviso de avance y no avisa', function () {
    $run = queuedSolverRun('cancelable');

    scriptedSolverEngine('cancelable', function (SolverInput $input, ?Closure $progress) use ($run): never {
        $progress(10, null);
        app(CancelSolverRun::class)->handle(SolverRun::query()->findOrFail($run->id), $run->creator);
        $this->travel(2)->seconds();
        $progress(20, null);

        throw new LogicException('El motor no debió seguir después de la cancelación.');
    });

    RunSolver::dispatchSync($run->id);

    expect($run->fresh())
        ->status->toBe(SolverRunStatus::Cancelled)
        ->cancel_requested_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->output->toBeNull()
        ->error->toBeNull();

    Notification::assertNothingSent();
});

it('no corre una corrida que ya no está en cola, ni toca una terminada si el job falla después', function (SolverRunStatus $status) {
    $run = queuedSolverRun(attributes: ['status' => $status]);

    app()->call([new RunSolver($run->id), 'handle']);
    (new RunSolver($run->id))->failed(new RuntimeException('Tarde.'));
    app()->call([new RunSolver(fakeKey(1)), 'handle']);

    expect($run->fresh())
        ->status->toBe($status)
        ->output->toBeNull()
        ->error->toBeNull();

    Notification::assertNothingSent();
})->with([SolverRunStatus::Done, SolverRunStatus::Cancelled, SolverRunStatus::Discarded]);

it('espera el límite de la corrida más un minuto, no se reintenta y falla al agotar el tiempo', function () {
    $job = new RunSolver(fakeKey(1), 120);

    expect($job->timeout)->toBe(180)
        ->and($job->tries)->toBe(1)
        ->and($job->failOnTimeout)->toBeTrue()
        ->and((new RunSolver(fakeKey(1)))->timeout)->toBe(660);
});

it('el aviso lleva a la corrida y dice por qué ya no se abre (la ruta de la pantalla llega con la 6.10)', function () {
    $run = SolverRun::factory()->done()->create(['metrics' => ['unscheduled' => 0]]);
    $message = (new SolverRunFinished($run))->message($run->creator);
    $campus = Campus::factory()->create();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $run->department_id, 'campus_id' => $campus->id])->create();
    $docente = User::factory()->withRole(Role::Docente, ['campus_id' => $campus->id])->create();
    $context = fn (User $user): Actor => app(Actor::class)->forUser($user);

    Route::get('/_corridas-de-prueba', fn (): string => 'ok')->name('solver.runs');
    app('router')->getRoutes()->refreshNameLookups();

    expect($message->body)->toBe("{$run->modality->name}, {$run->term->name}: todos los grupos quedaron acomodados.")
        ->and(SolverRunFinished::unavailableReason($context($jefe), $message))->toBeNull()
        ->and(SolverRunFinished::unavailableReason($context($docente), $message))->toBe('Ya no puedes ver las corridas del motor.');

    $run->delete();

    expect(SolverRunFinished::unavailableReason($context($jefe), $message))->toBe('Esa corrida ya no existe.');
});
