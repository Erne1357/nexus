<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Database\Seeders\SolverRunsDemoSeeder;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverRunStatus;
use App\Modules\Solver\Models\SolverRun;
use App\Modules\Solver\Queries\SolverRunsQuery;
use Database\Seeders\DemoSeeder;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire;

/*
| Las corridas del motor sobre la demo de verdad (volumen demo, como al final de `todo`; revisión del Riel B en #635):
| DemoSeeder y después SolverRunsDemoSeeder con la aceptación. Ninguna revisión de la demo por puntos cubre la aplicada,
| porque `todo` no corre revisiones: lo revisa esta prueba, con la pantalla que ven el jefe y el coordinador.
*/

/**
 * Lo que la pantalla de corridas pinta como `$user`, sin campus en la URL.
 */
function demoAppliedRunsPage(User $user)
{
    onCampusPage(null);

    return Livewire::actingAs($user)->test('solver::pages.runs');
}

it('con la demo: acepta una de verdad con los choques recalculados, las listas siguen vigentes, ninguna llega al plazo y la pantalla del jefe y la del coordinador abren su lista', function () {
    seedDemoForBrowser();
    app(SolverRunsDemoSeeder::class)->run('PRES', ['C2'], '20271', apply: true);
    $term = Term::query()->where('code', '20271')->sole();
    $pres = Modality::query()->where('code', 'PRES')->sole();

    // La aplicada, con su aviso de choques recalculados (`conflicts_pending` falso), y las listas, vigentes (SV-02).
    $applied = SolverRun::query()->where('status', SolverRunStatus::Applied)->sole();
    $notice = User::query()->findOrFail($applied->applied_by)->notifications
        ->first(fn (DatabaseNotification $notification): bool => ($notification->data['parameters']['corrida'] ?? null) === $applied->id);
    $done = SolverRun::query()->where('scope_kind', RunScope::Department)->where('status', SolverRunStatus::Done)->with('department')->get();

    expect($applied->engine)->toBe('heuristic')
        ->and($notice->data['body'])->toEndWith('choques recalculados.')
        ->and($done->pluck('department.code')->all())->toContain(SolverRunsDemoSeeder::MAIN_DEPARTMENT)
        ->and($done->reject(fn (SolverRun $run): bool => app(ApplySolverRun::class)->isCurrent($run))->map(fn (SolverRun $run): string => "{$run->department->code} {$run->engine}")->values()->all())->toBe([]);

    // Ninguna de la heurística llega a su plazo con el volumen demo: sus números no dependen de la máquina.
    $heuristic = SolverRun::query()->where('engine', 'heuristic')->whereNotNull('output')->get();

    expect($heuristic->count())->toBeGreaterThanOrEqual(4)
        ->and($heuristic->filter(fn (SolverRun $run): bool => SolverRunsDemoSeeder::reachedDeadline($run))->count())->toBe(0);

    // El jefe de SC abre su lista de la heurística, vigente, con los estados de su carga.
    $jefe = User::query()->where('username', 'jefe.sc')->sole();
    $opened = app(SolverRunsQuery::class)->forDepartment($term, $pres, Department::query()->where('code', SolverRunsDemoSeeder::MAIN_DEPARTMENT)->sole(), $jefe)[0]['run'];

    expect($opened->engine)->toBe('heuristic')->and($opened->status)->toBe(SolverRunStatus::Done);
    demoAppliedRunsPage($jefe)
        ->assertOk()
        ->assertSet('runId', $opened->id)
        ->assertSeeHtml('data-testid="run-compare"')
        ->assertSee(['Corridas de la carga', 'Lista', 'Descartada', 'En cola', 'Cancelada', 'Fallida', 'El servicio del motor no está disponible.'])
        ->call('openAccept')
        ->assertSet('showStale', false)
        ->assertSet('showAccept', true);

    // El coordinador abre la lista de su carrera y ve todos los estados, la que corre y la aplicada.
    $careerRun = SolverRun::query()->where('scope_kind', RunScope::Career)->where('status', SolverRunStatus::Done)->sole();
    $coordinator = User::query()->findOrFail($careerRun->created_by);
    $seen = app(SolverRunsQuery::class)->forCareer($term, $pres, Career::query()->findOrFail($careerRun->career_id), $coordinator);

    expect($seen[0]['run']->id)->toBe($careerRun->id)
        ->and(collect($seen)->map(fn (array $row): string => $row['run']->status->value)->unique()->sort()->values()->all())
        ->toBe(['applied', 'cancelled', 'discarded', 'done', 'failed', 'queued', 'running']);
    demoAppliedRunsPage($coordinator)
        ->assertOk()
        ->assertSet('runId', $careerRun->id)
        ->assertSeeHtml('data-testid="run-compare"')
        ->assertSee(['Corridas que ves de tu carrera', 'Corriendo', 'Aplicada', 'En cola', 'Cancelada', 'Descartada', 'Fallida']);
});
