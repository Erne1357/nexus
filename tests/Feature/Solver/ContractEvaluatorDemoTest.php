<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoRunner;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Queries\ScheduleStateQuery;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Solver\Queries\BuildSolverInput;
use Illuminate\Support\Facades\DB;
use Tests\Support\Solver\Verdicts;

/*
| La equivalencia de la tarea 6.5 con la demo (volumen demo, al punto `cierre`: grupos con horario, docente y salón):
| el horario de la demo no tiene choques, así que se mueve a propósito, siempre igual, y cada carga presencial debe dar
| en memoria, sobre el contrato del motor, las mismas reglas duras por grupo que el validador contra la base, también el
| núcleo sin combinación de HC-03 (la clave `combination:` y el semestre completo de cada movible; docs/arquitectura/
| solver.md, «Evaluador en memoria»).
*/

/**
 * Mueve a propósito, siempre igual, las sesiones presenciales del periodo: cada sexta al lunes a las 7:00 (empalmes de
 * docente, salón y alumno tipo), cada novena media hora más tarde (fuera de bloque), cada décima fuera (faltan horas),
 * cada quinceava a otro salón de su campus (capacidad, laboratorio, préstamo) y cada diecisieteava al sábado (fuera de
 * la ventana).
 */
function demoEquivalenceShake(Term $term, Modality $modality): void
{
    DB::statement('set constraints all deferred');

    $rooms = Room::query()->orderBy('code')->get(['id', 'campus_id'])->groupBy('campus_id');
    $sessions = DB::table('group_sessions')->join('groups', 'groups.id', '=', 'group_sessions.group_id')
        ->where('groups.term_id', $term->id)->where('groups.modality_id', $modality->id)
        ->orderBy('group_sessions.id')
        ->get(['group_sessions.id', 'group_sessions.starts_at', 'group_sessions.ends_at', 'group_sessions.campus_id']);

    foreach ($sessions->values() as $i => $session) {
        $start = Minutes::of($session->starts_at);
        $length = Minutes::of($session->ends_at) - $start;
        $row = DB::table('group_sessions')->where('id', $session->id);

        match (true) {
            $i % 6 === 0 => $row->update(['day' => 1, 'starts_at' => '07:00', 'ends_at' => Minutes::format(420 + $length)]),
            $i % 9 === 1 => $row->update(['starts_at' => Minutes::format($start + 30), 'ends_at' => Minutes::format($start + 30 + $length)]),
            $i % 10 === 2 => $row->delete(),
            $i % 15 === 3 => $row->update(['room_id' => $rooms[$session->campus_id][$i % count($rooms[$session->campus_id])]->id]),
            $i % 17 === 4 => $row->update(['day' => 6]),
            default => null,
        };
    }
}

it('con la demo y su horario movido a propósito, cada carga presencial da en memoria las mismas reglas duras por grupo que la base', function () {
    $demo = DemoContext::for(DemoVolume::Demo);
    $report = app(DemoRunner::class)->run(DemoCheckpoint::Closing, $demo);
    $failed = array_map(fn (array $check): string => "{$check['checkpoint']->value}: {$check['label']} ({$check['reason']})", array_values(array_filter($report->checks, fn (array $check): bool => ! $check['passed'])));

    expect($failed)->toBe([], 'La demo no llegó al cierre: sin docentes ni salones no hay qué comparar.')
        ->and(array_column($report->checks, 'checkpoint'))->toContain(DemoCheckpoint::Closing);
    $term = $demo->term();
    $pres = modalityOf('PRES');

    demoEquivalenceShake($term, $pres);

    $state = app(ScheduleStateQuery::class)->forModality($term, $pres->id);
    $seen = [];
    $loads = 0;

    foreach (Department::query()->orderBy('name')->get() as $department) {
        $input = app(BuildSolverInput::class)->forDepartment($term, $pres, $department, 'run-demo')->input;
        [$fromDatabase, $inMemory] = Verdicts::of($input, $state);

        expect($inMemory)->toBe($fromDatabase, "La carga de {$department->name} no da lo mismo en memoria que en la base.");

        $loads += $input->groups === [] ? 0 : 1;

        foreach ($fromDatabase as $rules) {
            $seen = [...$seen, ...array_fill_keys($rules, true)];
        }
    }

    $seen = array_keys($seen);
    sort($seen);

    expect($loads)->toBeGreaterThan(1)
        ->and($seen)->toContain('HC-01', 'HC-02', 'HC-03', 'HC-04', 'HC-12');
});
