<?php

use App\Modules\Scheduling\Enums\ConflictSeverity;
use App\Modules\Scheduling\Rules\Constraints\AdministrativeHours;
use App\Modules\Scheduling\Rules\Constraints\TeacherHours;
use App\Modules\Scheduling\Validation\ValidationScope;
use Tests\Support\Scheduling\States;

/*
| HC-22 (tarea 1.80, confirmado el 27 de noviembre de 2026: «el límite es 42»): quien tiene plaza administrativa no puede pasar
| de 42 h entre horas administrativas, clases y externas. Es choque duro; HC-08 (la banda de ±2) no le aplica (PL-03).
*/

function administrativeState(int $theory, int $external = 0, ?int $cap = null)
{
    $builder = States::builder();

    if ($cap !== null) {
        $builder->setting('staffing.administrative_max_hours', $cap);
    }

    return $builder
        ->teacher(States::teacher('T1', 'Ana Pérez', base: 36, external: $external, administrative: 36))
        ->subject(States::subject('S-A', theory: $theory, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();
}

it('HC-22: 36 + 8 pasa de 42 y es choque duro, con su desglose', function () {
    $violations = [...(new AdministrativeHours)->check(administrativeState(8), ValidationScope::groups('A'))];

    expect($violations)->toHaveCount(1)
        ->and($violations[0])
        ->rule->toBe('HC-22')
        ->severity->toBe(ConflictSeverity::Hard)
        ->message->toStartWith('Ana Pérez pasa el tope de su plaza administrativa (42 h)')
        ->related->toMatchArray(['administrative_hours' => 36, 'administrative_cap' => 42, 'assigned_hours' => 8, 'excess_hours' => 2]);
});

it('HC-22: 36 + 6 = 42 sí se permite; y con 4 h externas, 36 + 6 + 4 ya no', function () {
    expect([...(new AdministrativeHours)->check(administrativeState(6), ValidationScope::groups('A'))])->toBe([])
        ->and([...(new AdministrativeHours)->check(administrativeState(6, external: 4), ValidationScope::groups('A'))])->toHaveCount(1);
});

it('HC-22: el tope es staffing.administrative_max_hours', function () {
    expect([...(new AdministrativeHours)->check(administrativeState(8, cap: 50), ValidationScope::groups('A'))])->toBe([])
        ->and([...(new AdministrativeHours)->check(administrativeState(8, cap: 40), ValidationScope::groups('A'))])->toHaveCount(1);
});

it('PL-03: HC-08 no le aplica a la plaza administrativa (sin banda de ±2) aunque pase de 42', function () {
    expect([...(new TeacherHours)->check(administrativeState(20), ValidationScope::groups('A'))])->toBe([]);
});

it('HC-22 y PL-04: con plaza docente pasa el tope de 44 h (plaza más extra) y es choque duro por grupo', function () {
    $state = States::builder()
        ->teacher(States::teacher('T1', 'Docente Uno', base: 40, teaching: 22))
        ->subject(States::subject('S-A', theory: 29, practice: 0))
        ->group(States::group('A', teacher: 'T1', subject: 'S-A'))
        ->build();

    $violations = collect((new AdministrativeHours)->check($state, ValidationScope::teacher('T1')));

    expect($violations)->toHaveCount(1)
        ->and($violations->first()->severity->value ?? null)->not->toBeNull()
        ->and($violations->first()->rule)->toBe('HC-22')
        ->and($violations->first()->message)->toContain('tope con honorarios (44 h)');
});
