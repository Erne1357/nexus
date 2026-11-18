<?php

use App\Modules\Solver\Contract\SolverInput;
use App\Modules\Solver\Contract\SolverOutput;
use App\Modules\Solver\Rules\ApplyPlan;

/*
| Qué escribe aceptar una corrida (SV-05, SV-06; tarea 6.10): los grupos cuyo horario propuesto es distinto del de hoy se
| escriben; los iguales no se tocan; los sin acomodar, los de una violación dura, los que no recibieron sesiones y los que
| chocarían con lo que se queda se quedan como están, con su motivo.
*/

const PLAN_ROOM = '0190a000-0000-7000-8000-000000000601';
const PLAN_TEACHER = '0190a000-0000-7000-8000-000000000501';

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function planGroup(string $id, array $overrides = []): array
{
    return [
        'id' => $id, 'campus_code' => 'C1', 'subject_code' => 'SCD-'.substr($id, -4), 'department_id' => 'd1', 'owner_career_id' => 'c1',
        'study_plan_id' => 'p1', 'semester' => 1, 'weekly_hours' => 2, 'practice_hours' => 0, 'needs_lab' => false, 'capacity' => 30,
        'teacher_id' => PLAN_TEACHER, 'cohorts' => [], 'fixed_sessions' => [], 'candidate_room_ids' => [], 'movable' => true, 'current_sessions' => [],
        ...$overrides,
    ];
}

/**
 * @return array{day: int, starts_at: string, ends_at: string, kind: string, room_id: string}
 */
function planSlot(int $day, string $from, string $to): array
{
    return ['day' => $day, 'starts_at' => $from, 'ends_at' => $to, 'kind' => 'teoria', 'room_id' => PLAN_ROOM];
}

/**
 * @param  list<array<string, mixed>>  $groups
 * @param  list<array<string, mixed>>  $teacherBusy
 */
function planInput(array $groups, array $teacherBusy = []): SolverInput
{
    return SolverInput::fromArray([
        'version' => 1, 'run_id' => 'r1', 'term' => ['code' => '20271'], 'modality' => ['code' => 'PRES'],
        'campuses' => [['code' => 'C1', 'block_minutes' => 60, 'day_start' => '07:00', 'day_end' => '14:00', 'days' => [1, 2, 3, 4, 5], 'uses_rooms' => true]],
        'time_limit_seconds' => 10, 'scope' => ['kind' => 'department', 'id' => 'd1'], 'travel_minutes' => 60, 'groups' => $groups,
        'teachers' => [['id' => PLAN_TEACHER, 'remaining_hours' => 20, 'honorarios' => false, 'unavailable' => [], 'preferred' => [], 'busy' => $teacherBusy]],
        'rooms' => [['id' => PLAN_ROOM, 'campus_code' => 'C1', 'type' => 'aula', 'capacity' => 40, 'building_id' => 'b1', 'blocks' => [], 'busy' => []]],
        'hard_rules' => ['HC-01'], 'soft_weights' => [],
    ]);
}

/**
 * @param  array<string, list<array<string, mixed>>>  $sessions  por grupo
 * @param  array<string, mixed>  $extra
 */
function planOutput(array $sessions, array $extra = []): SolverOutput
{
    $proposed = [];

    foreach ($sessions as $groupId => $slots) {
        foreach ($slots as $slot) {
            $proposed[] = ['group_id' => $groupId, ...$slot, 'changed' => true];
        }
    }

    return SolverOutput::fromArray([
        'version' => 1, 'run_id' => 'r1', 'status' => 'partial', 'sessions' => $proposed, 'unscheduled' => [], 'hard_violations' => [],
        'soft_score' => ['total' => 0, 'by_rule' => []], 'metrics' => ['elapsed_seconds' => 0.1, 'engine' => 'fixed', 'engine_version' => '1.0'],
        ...$extra,
    ]);
}

it('escribe lo distinto, no toca lo igual y une las horas que se pegan para comparar', function () {
    $input = planInput([
        planGroup('g-0001', ['current_sessions' => [planSlot(1, '07:00', '09:00')]]),
        planGroup('g-0002', ['current_sessions' => [planSlot(2, '07:00', '09:00')]]),
    ]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0001' => [planSlot(1, '07:00', '08:00'), planSlot(1, '08:00', '09:00')],
        'g-0002' => [planSlot(3, '10:00', '11:00'), planSlot(3, '11:00', '12:00')],
    ]));

    expect($plan->status('g-0001'))->toBe(ApplyPlan::SAME)
        ->and($plan->writes())->toBe(['g-0002' => [planSlot(3, '10:00', '12:00')]])
        ->and($plan->kept())->toBe([]);
});

it('SV-06: los sin acomodar, los de una violación dura y los que no recibieron sesiones se quedan, con su motivo', function () {
    $input = planInput([planGroup('g-0001'), planGroup('g-0002'), planGroup('g-0003'), planGroup('g-0004', ['teacher_id' => null])]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0002' => [planSlot(1, '07:00', '09:00')],
        'g-0004' => [planSlot(2, '07:00', '09:00')],
    ], [
        'status' => 'infeasible',
        'unscheduled' => [['group_id' => 'g-0001', 'reasons' => ['HC-12: no caben sus horas']]],
        'hard_violations' => [['rule' => 'HC-05', 'group_ids' => ['g-0002'], 'message' => 'El salón es chico.']],
    ]));

    expect($plan->kept())->toBe([
        'g-0001' => ['HC-12: no caben sus horas'],
        'g-0002' => ['HC-05: El salón es chico.'],
        'g-0003' => ['El motor no le propuso sesiones.'],
    ])->and(array_keys($plan->writes()))->toBe(['g-0004']);
});

it('SV-06: un grupo que chocaría con lo que se queda también se queda, y eso se repite hasta que nada cambia', function () {
    $input = planInput([
        planGroup('g-0001', ['current_sessions' => [planSlot(1, '07:00', '09:00')]]),
        planGroup('g-0002', ['current_sessions' => [planSlot(4, '07:00', '09:00')]]),
        planGroup('g-0003'),
        planGroup('g-0004'),
    ], [['day' => 5, 'starts_at' => '07:00', 'ends_at' => '08:00']]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0002' => [planSlot(1, '08:00', '10:00')],
        'g-0003' => [planSlot(4, '08:00', '09:00')],
        'g-0004' => [planSlot(5, '07:00', '08:00')],
    ], ['unscheduled' => [['group_id' => 'g-0001', 'reasons' => ['Sin lugar.']]]]));

    expect($plan->kept())->toBe([
        'g-0001' => ['Sin lugar.'],
        'g-0002' => ['HC-01: su docente ya tiene clase el lunes de 8:00 a 10:00; se queda como está.'],
        'g-0003' => ['HC-01: su docente ya tiene clase el jueves de 8:00 a 9:00; se queda como está.'],
        'g-0004' => ['HC-01: su docente ya tiene clase el viernes de 7:00 a 8:00; se queda como está.'],
    ])->and($plan->writes())->toBe([]);
});

it('dos grupos que intercambian sus horas se escriben los dos', function () {
    $input = planInput([
        planGroup('g-0001', ['current_sessions' => [planSlot(1, '07:00', '09:00')]]),
        planGroup('g-0002', ['current_sessions' => [planSlot(1, '09:00', '11:00')]]),
    ]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0001' => [planSlot(1, '09:00', '11:00')],
        'g-0002' => [planSlot(1, '07:00', '09:00')],
    ]));

    expect(array_keys($plan->writes()))->toBe(['g-0001', 'g-0002'])->and($plan->kept())->toBe([]);
});

it('SV-06: la cascada alcanza a un grupo que ya se escribía cuando otro, después en el orden, se queda', function () {
    $input = planInput([
        planGroup('g-0001', ['current_sessions' => [planSlot(1, '07:00', '08:00')]]),
        planGroup('g-0002', ['current_sessions' => [planSlot(2, '07:00', '08:00')]]),
    ], [['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00']]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0001' => [planSlot(2, '07:00', '08:00')],
        'g-0002' => [planSlot(3, '07:00', '08:00')],
    ]));

    expect($plan->kept())->toBe([
        'g-0001' => ['HC-01: su docente ya tiene clase el martes de 7:00 a 8:00; se queda como está.'],
        'g-0002' => ['HC-01: su docente ya tiene clase el miércoles de 7:00 a 8:00; se queda como está.'],
    ])->and($plan->writes())->toBe([]);
});

it('SV-06: un grupo con solo HC-08 se escribe y la violación queda en los problemas de entrada', function () {
    $input = planInput([planGroup('g-0001'), planGroup('g-0002')]);

    $plan = ApplyPlan::of($input, planOutput([
        'g-0001' => [planSlot(1, '07:00', '09:00')],
        'g-0002' => [planSlot(2, '07:00', '09:00')],
    ], ['hard_violations' => [['rule' => 'HC-08', 'group_ids' => ['g-0001', 'g-0002'], 'message' => 'El docente no tiene horas libres.']]]));

    expect(array_keys($plan->writes()))->toBe(['g-0001', 'g-0002'])
        ->and($plan->kept())->toBe([])
        ->and($plan->inputProblems)->toBe([['rule' => 'HC-08', 'group_ids' => ['g-0001', 'g-0002'], 'message' => 'El docente no tiene horas libres.']]);
});

it('SV-06: un grupo con HC-01 se retiene', function () {
    $plan = ApplyPlan::of(planInput([planGroup('g-0001')]), planOutput(
        ['g-0001' => [planSlot(1, '07:00', '09:00')]],
        ['hard_violations' => [['rule' => 'HC-01', 'group_ids' => ['g-0001'], 'message' => 'Docente empalmado.']]],
    ));

    expect($plan->kept())->toBe(['g-0001' => ['HC-01: Docente empalmado.']])->and($plan->inputProblems)->toBe([]);
});

it('SV-06: un grupo con HC-08 y HC-02 se retiene solo por la de acomodo', function () {
    $plan = ApplyPlan::of(planInput([planGroup('g-0001')]), planOutput(
        ['g-0001' => [planSlot(1, '07:00', '09:00')]],
        ['hard_violations' => [
            ['rule' => 'HC-08', 'group_ids' => ['g-0001'], 'message' => 'Sin horas.'],
            ['rule' => 'HC-02', 'group_ids' => ['g-0001'], 'message' => 'Salón empalmado.'],
        ]],
    ));

    expect($plan->kept())->toBe(['g-0001' => ['HC-02: Salón empalmado.']])
        ->and(array_column($plan->inputProblems, 'rule'))->toBe(['HC-08']);
});
