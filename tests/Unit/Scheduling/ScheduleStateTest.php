<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Enums\SessionKind;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Scheduling\Validation\SessionCandidate;
use App\Modules\Scheduling\Validation\ValidationResult;
use App\Modules\Scheduling\Validation\Violation;
use Tests\Support\Scheduling\States;

/*
| La foto en memoria que leen las reglas del validador (tarea 3.2) y el resultado que devuelve.
*/

it('indexa las sesiones de los grupos activos por docente, por salón y por plan y semestre', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1', semester: 3))
        ->group(States::group('B', teacher: 'T1', active: false, semester: 3))
        ->group(States::group('C', campus: 'C1', semester: 3))
        ->session('A', 1, '07:00', '09:00', 'R1')
        ->session('B', 1, '07:00', '09:00', 'R1')
        ->build();

    expect($state->teacherSessions('T1'))->toHaveCount(1)
        ->and($state->roomSessions('R1'))->toHaveCount(1)
        ->and($state->sessionsOf('B'))->toHaveCount(1)
        ->and(array_map(fn ($group) => $group->id, $state->groupsOfSemester('C2', 'P1', 3)))->toBe(['A'])
        ->and($state->semestersOf('C2'))->toBe([['P1', 3]]);
});

it('withSession agrega una candidata o mueve la sesión que reemplaza, sin tocar la foto original', function () {
    $state = States::builder()
        ->group(States::group('A'))
        ->session('A', 1, '07:00', '09:00', 'R1', id: 's1')
        ->build();

    $added = $state->withSession('A', new SessionCandidate(2, '07:00', '09:00', 'R2', SessionKind::Practica));
    $moved = $state->withSession('A', new SessionCandidate(3, '10:00', '11:00', replacesSessionId: 's1'));

    expect($state->sessionsOf('A'))->toHaveCount(1)
        ->and($added->sessionsOf('A'))->toHaveCount(2)
        ->and($added->roomSessions('R2')[0]->kind)->toBe(SessionKind::Practica)
        ->and($moved->sessionsOf('A'))->toHaveCount(1)
        ->and($moved->sessionsOf('A')[0])->day->toBe(3)->start->toBe(600)->end->toBe(660)->id->toBe('s1');
});

it('withTeacher cambia el docente del grupo y reindexa sus sesiones', function () {
    $state = States::builder()
        ->group(States::group('A', teacher: 'T1'))
        ->session('A', 1, '07:00', '09:00')
        ->build();

    $other = $state->withTeacher('A', 'T2');

    expect($other->teacherSessions('T1'))->toBe([])
        ->and($other->teacherSessions('T2'))->toHaveCount(1)
        ->and($state->teacherSessions('T1'))->toHaveCount(1)
        ->and($other->withTeachers([States::teacher('T2', 'Luis')])->teacher('T2')->name)->toBe('Luis');
});

it('sin perfil cargado usa el de omisión y nombra los grupos con su materia', function () {
    $state = States::builder()
        ->subject(States::subject('S-A', 'Cálculo Integral'))
        ->group(States::group('A', code: 'D'))
        ->build();

    expect($state->profile('C2')->days)->toBe([1, 2, 3, 4, 5])
        ->and($state->labelOf('A'))->toBe('Cálculo Integral, grupo D')
        ->and(fn () => $state->groupOrFail('Z'))->toThrow(LogicException::class);
});

it('las horas se comparan en minutos y dos horas contiguas no se empalman', function () {
    expect(Minutes::of('07:30:00'))->toBe(450)
        ->and(Minutes::format(450))->toBe('07:30')
        ->and(Minutes::dayName(6))->toBe('sábado')
        ->and(Minutes::overlap(420, 540, 540, 600))->toBeFalse()
        ->and(Minutes::overlap(420, 540, 480, 600))->toBeTrue();
});

it('el resultado quita las violaciones repetidas y las filtra por regla, grupo y gravedad', function () {
    $hard = Violation::hard('HC-02', 'Ocupado', 'room', 'R1', 'A');
    $soft = Violation::soft('HC-12', 'Faltan horas', 'group', 'B', 'B');

    $result = new ValidationResult([$hard, $hard, $soft]);

    expect($result->violations())->toBe([$hard, $soft])
        ->and($result->hasHard())->toBeTrue()
        ->and($result->forRule('HC-12'))->toBe([$soft])
        ->and($result->forGroup('A'))->toBe([$hard])
        ->and($result->messages())->toBe(['Ocupado', 'Faltan horas'])
        ->and((new ValidationResult([$soft]))->hasHard())->toBeFalse()
        ->and((new ValidationResult)->isEmpty())->toBeTrue()
        ->and($soft->severity)->toBe(ConflictSeverity::Soft);
});
