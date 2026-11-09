<?php

use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Support\CleanSchedulePlanner;
use App\Modules\Scheduling\Support\RoomSupply;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\State\StateRoom;

/*
| El horario limpio de la demo por puntos (punto «asignacion»): coloca las sesiones de cada grupo con sus horas exactas
| (HC-12), dentro de la ventana del campus y alineadas a sus bloques (HC-04), sin que los grupos de un mismo plan y
| semestre dejen al alumno tipo sin combinación (HC-03), con un salón posible para cada sesión presencial (HC-02,
| HC-05, HC-06) y sin pasar del tope de grupos simultáneos de cada departamento, para que en el punto siguiente haya
| docentes que no choquen (HC-01). Las horas van en minutos desde la medianoche.
*/

const PLANNER_DAYS = [1, 2, 3, 4, 5];

/**
 * @param  list<array{0: int, 1: SessionKind, 2?: bool}>  $pieces  [minutos, tipo, espacio de práctica]
 * @return array{id: string, label: string, cohort: string, subject: string, department: ?string, capacity: int, pieces: list<array{minutes: int, kind: SessionKind, practiceSpace: bool}>}
 */
function plannerGroup(string $id, string $cohort, string $subject, array $pieces = [[120, SessionKind::Teoria], [120, SessionKind::Teoria]], ?string $department = 'D1', int $capacity = 30): array
{
    return [
        'id' => $id,
        'label' => "Grupo {$id}",
        'cohort' => $cohort,
        'subject' => $subject,
        'department' => $department,
        'capacity' => $capacity,
        'pieces' => array_map(fn (array $piece): array => ['minutes' => $piece[0], 'kind' => $piece[1], 'practiceSpace' => $piece[2] ?? false], $pieces),
    ];
}

function plannerRooms(int $classrooms = 20, int $labs = 0, int $capacity = 40): RoomSupply
{
    $rooms = [];

    for ($i = 1; $i <= $classrooms; $i++) {
        $rooms[] = new StateRoom("aula-{$i}", 'campus', sprintf('A%02d', $i), $capacity);
    }

    for ($i = 1; $i <= $labs; $i++) {
        $rooms[] = new StateRoom("lab-{$i}", 'campus', sprintf('L%02d', $i), $capacity, RoomType::Laboratorio);
    }

    return new RoomSupply($rooms);
}

/**
 * @param  array<string, list<array{day: int, start: int, end: int, kind: SessionKind}>>  $plan
 */
function plannerOverlap(array $plan, string $a, string $b): bool
{
    foreach ($plan[$a] as $mine) {
        foreach ($plan[$b] as $other) {
            if ($mine['day'] === $other['day'] && Minutes::overlap($mine['start'], $mine['end'], $other['start'], $other['end'])) {
                return true;
            }
        }
    }

    return false;
}

it('parte las horas de la materia en sesiones de dos horas, con la práctica de laboratorio aparte', function () {
    expect(CleanSchedulePlanner::pieces(3, 2, true, 5))->toBe([
        ['minutes' => 120, 'kind' => SessionKind::Teoria, 'practiceSpace' => false],
        ['minutes' => 60, 'kind' => SessionKind::Teoria, 'practiceSpace' => false],
        ['minutes' => 120, 'kind' => SessionKind::Practica, 'practiceSpace' => true],
    ])->and(CleanSchedulePlanner::pieces(4, 1, false, 5))->toBe([
        ['minutes' => 120, 'kind' => SessionKind::Teoria, 'practiceSpace' => false],
        ['minutes' => 120, 'kind' => SessionKind::Teoria, 'practiceSpace' => false],
        ['minutes' => 60, 'kind' => SessionKind::Teoria, 'practiceSpace' => false],
    ])->and(CleanSchedulePlanner::pieces(0, 4, true, 5))->toBe([
        ['minutes' => 120, 'kind' => SessionKind::Practica, 'practiceSpace' => true],
        ['minutes' => 120, 'kind' => SessionKind::Practica, 'practiceSpace' => true],
    ])->and(array_sum(array_column(CleanSchedulePlanner::pieces(5, 5, true, 5), 'minutes')))->toBe(600)
        ->and(count(CleanSchedulePlanner::pieces(5, 5, true, 5)))->toBe(5)
        ->and(CleanSchedulePlanner::pieces(0, 0, false, 5))->toBe([]);
});

it('da a cada grupo sus horas exactas, en días distintos, dentro de la ventana y alineadas a los bloques', function () {
    $groups = [
        plannerGroup('a', 'P1|1', 'S1', [[120, SessionKind::Teoria], [60, SessionKind::Teoria], [120, SessionKind::Practica, true]]),
        plannerGroup('b', 'P1|1', 'S2'),
    ];

    $plan = (new CleanSchedulePlanner)->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms(labs: 2));

    foreach ($groups as $group) {
        $sessions = $plan[$group['id']];

        expect(array_sum(array_map(fn (array $session): int => $session['end'] - $session['start'], $sessions)))->toBe(array_sum(array_column($group['pieces'], 'minutes')))
            ->and(count(array_unique(array_column($sessions, 'day'))))->toBe(count($sessions))
            ->and(array_column($sessions, 'kind'))->toBe(array_column($group['pieces'], 'kind'));

        foreach ($sessions as $session) {
            expect($session['start'])->toBeGreaterThanOrEqual(420)
                ->and($session['end'])->toBeLessThanOrEqual(1260)
                ->and(($session['start'] - 420) % 60)->toBe(0)
                ->and(PLANNER_DAYS)->toContain($session['day']);
        }
    }
});

it('no encima los grupos de un mismo plan y semestre, así el alumno tipo tiene combinación (HC-03)', function () {
    $groups = array_map(fn (int $n): array => plannerGroup("g{$n}", 'P1|3', "S{$n}"), range(1, 8));

    $plan = (new CleanSchedulePlanner)->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms());

    foreach (range(1, 7) as $a) {
        foreach (range($a + 1, 8) as $b) {
            expect(plannerOverlap($plan, "g{$a}", "g{$b}"))->toBeFalse();
        }
    }
});

it('acomoda un semestre lleno aunque la misma hora en todos los días ya no quepa', function () {
    $pieces = CleanSchedulePlanner::pieces(3, 2, false, 5);
    $groups = array_map(fn (int $n): array => plannerGroup("g{$n}", 'P1|7', "S{$n}", array_map(fn (array $piece): array => [$piece['minutes'], $piece['kind']], $pieces)), range(1, 12));

    $plan = (new CleanSchedulePlanner)->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms());

    foreach (range(1, 11) as $a) {
        foreach (range($a + 1, 12) as $b) {
            expect(plannerOverlap($plan, "g{$a}", "g{$b}"))->toBeFalse();
        }
    }

    expect(array_sum(array_map(fn (array $sessions): int => array_sum(array_map(fn (array $session): int => $session['end'] - $session['start'], $sessions)), $plan)))->toBe(12 * 300);
});

it('no pasa del tope de grupos simultáneos de un departamento, aun en planes distintos', function () {
    $groups = array_map(fn (int $n): array => plannerGroup("g{$n}", "P{$n}|1", 'S1'), range(1, 6));

    $plan = (new CleanSchedulePlanner(['D1' => 2]))->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms());
    $load = [];

    foreach ($plan as $sessions) {
        foreach ($sessions as $session) {
            for ($minute = $session['start']; $minute < $session['end']; $minute += 60) {
                $load["{$session['day']}|{$minute}"] = ($load["{$session['day']}|{$minute}"] ?? 0) + 1;
            }
        }
    }

    expect(max($load))->toBeLessThanOrEqual(2);
});

it('el tope del departamento cuenta los grupos de todos los campus que coloca la misma instancia', function () {
    $planner = new CleanSchedulePlanner(['D1' => 1]);
    $first = $planner->plan([plannerGroup('c1', 'C1|P|1', 'S1', [[120, SessionKind::Teoria]])], [1], 420, 540, 60, plannerRooms());
    $second = $planner->plan([plannerGroup('c2', 'C2|P|1', 'S1', [[120, SessionKind::Teoria]])], [1, 2], 420, 540, 60, plannerRooms());

    expect(plannerOverlap([...$first, ...$second], 'c1', 'c2'))->toBeFalse();
});

it('solo coloca una sesión donde le queda un salón: la práctica de laboratorio en un espacio de práctica (HC-02, HC-06)', function () {
    $groups = array_map(fn (int $n): array => plannerGroup("g{$n}", "P{$n}|1", 'S1', [[120, SessionKind::Practica, true]], "D{$n}"), range(1, 3));

    $plan = (new CleanSchedulePlanner)->plan($groups, [1], 420, 780, 60, plannerRooms(classrooms: 10, labs: 1));

    expect(plannerOverlap($plan, 'g1', 'g2'))->toBeFalse()
        ->and(plannerOverlap($plan, 'g1', 'g3'))->toBeFalse()
        ->and(plannerOverlap($plan, 'g2', 'g3'))->toBeFalse();
});

it('falla con el grupo y el motivo cuando no cabe sin choque', function () {
    $planner = new CleanSchedulePlanner;

    expect(fn () => $planner->plan([plannerGroup('x', 'P1|1', 'S1', [[180, SessionKind::Teoria]])], [1], 420, 540, 60, plannerRooms()))
        ->toThrow(RuntimeException::class, 'Grupo x')
        ->and(fn () => $planner->plan([plannerGroup('y', 'P1|1', 'S1', capacity: 50)], PLANNER_DAYS, 420, 1260, 60, plannerRooms(capacity: 40)))
        ->toThrow(RuntimeException::class, 'salón');
});

it('sin salones que revisar (campus que no los usa) solo cuida el semestre, el departamento y la ventana', function () {
    $plan = (new CleanSchedulePlanner)->plan([plannerGroup('a', 'P1|1', 'S1', capacity: 500)], PLANNER_DAYS, 420, 1260, 60);

    expect($plan['a'])->toHaveCount(2);
});

it('da lo mismo con los mismos datos', function () {
    $groups = array_map(fn (int $n): array => plannerGroup("g{$n}", 'P'.($n % 3).'|1', "S{$n}"), range(1, 12));

    expect((new CleanSchedulePlanner(['D1' => 4]))->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms()))
        ->toBe((new CleanSchedulePlanner(['D1' => 4]))->plan($groups, PLANNER_DAYS, 420, 1260, 60, plannerRooms()));
});
