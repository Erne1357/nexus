<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Queries\RecipientsQuery;
use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\ScheduleConflict;
use App\Modules\Solver\Actions\ApplySolverRun;
use App\Modules\Solver\Actions\LaunchSolverRun;
use App\Modules\Solver\Engines\PhpHeuristic\PhpHeuristicEngine;
use App\Modules\Solver\Enums\RunScope;
use App\Modules\Solver\Enums\SolverPermission;
use App\Modules\Solver\Enums\SolverRunStatus;
use Illuminate\Support\Facades\Event;

/*
| Lo que la heurística dice al terminar es lo que el validador dice al aceptarla (seguimiento de 6.5 y 6.7). En la demo
| al punto `asignacion` (cero choques duros), la corrida de Ciencias Básicas terminaba con 0 duras y, al aceptarla, el
| validador marcaba HC-03 del núcleo sin combinación en el semestre 7: Desarrollo Sustentable (de CB, movible) quedaba
| a la hora del único grupo de Mercadotecnia Electrónica (de otro departamento) que el alumno tipo podía tomar, y esa
| materia, de tres grupos, no viajaba en el contrato.
*/

it('HC-03: aceptar la corrida de la heurística de Ciencias Básicas en la demo no deja choques duros que la corrida no dijo', function () {
    $demo = DemoContext::for(DemoVolume::Demo);
    $report = app(DemoRunner::class)->run(DemoCheckpoint::Staffing, $demo);
    $failed = array_values(array_filter($report->checks, fn (array $check): bool => ! $check['passed']));

    expect($failed)->toBe([], 'La demo no llegó a la asignación con cero choques duros.');

    $term = $demo->term();
    $pres = modalityOf('PRES');
    $basicas = Department::query()->where('code', 'CB')->firstOrFail();
    $head = app(RecipientsQuery::class)->holders(SolverPermission::DepartmentRunLaunch, Target::of(modality: $pres->id, unit: $basicas->id))[0]['user'];

    $run = app(LaunchSolverRun::class)->handle($term, $pres, RunScope::Department, $basicas->id, PhpHeuristicEngine::KEY, LaunchSolverRun::MIN_TIME_LIMIT, $head)->refresh();

    expect($run->status)->toBe(SolverRunStatus::Done)
        ->and($run->metrics['hard_violations'])->toBe(0);

    $summary = Event::fakeFor(fn (): array => app(ApplySolverRun::class)->handle($run, $head), [GroupScheduleChanged::class]);
    $conflicts = ScheduleConflict::query()->where('term_id', $term->id)->where('modality_id', $pres->id)
        ->where('severity', ConflictSeverity::Hard)->whereNull('resolved_at')
        ->get(['rule', 'message'])->map(fn (ScheduleConflict $conflict): string => "{$conflict->rule}: {$conflict->message}")->all();

    expect($summary['groups'])->toBeGreaterThan(0)
        ->and($conflicts)->toBe([]);
});
