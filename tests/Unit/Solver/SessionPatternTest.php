<?php

use App\Modules\Solver\Engines\PhpHeuristic\SessionPattern;

/*
| Los juegos de días de la heurística (tarea 6.6; reglas-de-negocio.md, «Patrón de sesiones»): una hora por día en días
| distintos; 4 horas de lunes a jueves, 5 de lunes a viernes y 6 con sábado (confirmado el 17 de noviembre de 2026).
| Después, otros días y, al final, dos horas el mismo día (supuestos por confirmar).
*/

it('el primer nivel es el patrón: 4 h de lunes a jueves, 5 de lunes a viernes y 6 con sábado', function () {
    expect(SessionPattern::tiers(4, [1, 2, 3, 4, 5])[0])->toBe([[1 => 1, 2 => 1, 3 => 1, 4 => 1]])
        ->and(SessionPattern::tiers(5, [1, 2, 3, 4, 5])[0])->toBe([[1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1]])
        ->and(SessionPattern::tiers(6, [1, 2, 3, 4, 5, 6])[0])->toBe([[1 => 1, 2 => 1, 3 => 1, 4 => 1, 5 => 1, 6 => 1]])
        ->and(SessionPattern::tiers(4, [1, 2, 3, 4, 5, 6])[0])->toBe([[1 => 1, 2 => 1, 3 => 1, 4 => 1]]);
});

it('si el patrón no cabe, prueba otros días de la misma cantidad, los más parecidos primero y el sábado al final', function () {
    $tiers = SessionPattern::tiers(4, [1, 2, 3, 4, 5, 6]);

    expect($tiers[1][0])->toBe([1 => 1, 2 => 1, 3 => 1, 5 => 1])
        ->and(array_keys(end($tiers[1])))->toBe([3, 4, 5, 6])
        ->and(array_map(fn (array $option): int => count($option), $tiers[1]))->each->toBe(4);
});

it('al final junta dos horas el mismo día, hasta dos por día', function () {
    $tiers = SessionPattern::tiers(5, [1, 2, 3, 4, 5]);

    expect($tiers)->toHaveCount(3)
        ->and($tiers[1][0])->toBe([1 => 2, 2 => 1, 3 => 1, 4 => 1])
        ->and($tiers[1])->toHaveCount(20)
        ->and($tiers[2][0])->toBe([1 => 2, 2 => 2, 3 => 1])
        ->and(max(array_map('max', $tiers[2])))->toBe(2);
});

it('con 6 horas y sin sábado, el patrón es de lunes a viernes con un día de dos horas', function () {
    $tiers = SessionPattern::tiers(6, [1, 2, 3, 4, 5]);

    expect($tiers[0])->toHaveCount(5)
        ->and($tiers[0][0])->toBe([1 => 2, 2 => 1, 3 => 1, 4 => 1, 5 => 1])
        ->and(array_sum($tiers[0][4]))->toBe(6);
});

it('sin días o sin horas no hay opciones', function () {
    expect(SessionPattern::tiers(0, [1, 2]))->toBe([])
        ->and(SessionPattern::tiers(3, []))->toBe([]);
});
