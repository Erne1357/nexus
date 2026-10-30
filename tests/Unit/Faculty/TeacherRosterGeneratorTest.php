<?php

use App\Modules\Faculty\Database\Seeders\TeacherRosterGenerator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| Generador de plantillas de docentes de los seeders demo y masivo (tarea 7b). Clase pura, sin base: con una
| semilla fija da los mismos docentes, con las plazas, adscripciones, disponibilidad e historial de los casos
| que pide docs/arquitectura/testing.md ("Datos de prueba").
*/

/**
 * @return list<array<string, mixed>>
 */
function roster(int $count = 60, string $campus = 'C2', int $seed = 2027, array $subjects = [8, 5, 0, 12]): array
{
    return (new TeacherRosterGenerator(new Randomizer(new Mt19937($seed))))->generate($campus, $count, $subjects);
}

it('con la misma semilla da los mismos docentes y con otra semilla, otros', function () {
    expect(roster())->toBe(roster())
        ->and(roster(seed: 1))->not->toBe(roster());
});

it('da un número de empleado único por docente y por campus', function () {
    $numbers = array_column(roster(80), 'employee_number');

    expect(array_unique($numbers))->toHaveCount(80)
        ->and(array_intersect($numbers, array_column(roster(80, 'C1'), 'employee_number')))->toBe([]);
});

it('cubre los cinco tipos de plaza, varias plazas a la vez, vencidas, futuras y docentes sin plaza', function () {
    $teachers = roster(40);
    $kinds = collect($teachers)->flatMap(fn (array $teacher) => array_column($teacher['positions'], 'kind'))->unique()->sort()->values()->all();

    expect($kinds)->toBe(['asignatura', 'honorarios', 'medio_tiempo', 'tiempo_completo', 'tres_cuartos'])
        ->and(collect($teachers)->contains(fn (array $teacher) => count(array_filter($teacher['positions'], fn (array $position) => $position['kind'] === 'tiempo_completo')) >= 3))->toBeTrue()
        ->and(collect($teachers)->contains(fn (array $teacher) => $teacher['positions'] === []))->toBeTrue()
        ->and(collect($teachers)->flatMap(fn (array $teacher) => $teacher['positions'])->contains(fn (array $position) => $position['to'] !== null))->toBeTrue()
        ->and(collect($teachers)->flatMap(fn (array $teacher) => $teacher['positions'])->contains(fn (array $position) => $position['from'] !== null && $position['from'] > '2027-01-01'))->toBeTrue();
});

it('respeta las horas de cada tipo de plaza', function () {
    $hours = ['tiempo_completo' => 40, 'tres_cuartos' => 30, 'medio_tiempo' => 20, 'honorarios' => 0];

    foreach (roster(80) as $teacher) {
        foreach ($teacher['positions'] as $position) {
            if ($position['kind'] === 'asignatura') {
                expect($position['hours'])->toBeBetween(1, 40);
            } else {
                expect($position['hours'])->toBe($hours[$position['kind']]);
            }
        }
    }
});

it('cada docente tiene una sola adscripción principal; algunos tienen varias y alguna está en el otro campus', function () {
    $teachers = roster(60);

    foreach ($teachers as $teacher) {
        expect(array_filter($teacher['adscriptions'], fn (array $adscription) => $adscription['primary']))->toHaveCount(1);
    }

    expect(collect($teachers)->contains(fn (array $teacher) => count($teacher['adscriptions']) > 1))->toBeTrue()
        ->and(collect($teachers)->flatMap(fn (array $teacher) => $teacher['adscriptions'])->contains(fn (array $adscription) => $adscription['campus'] === 'other'))->toBeTrue();
});

it('hay docentes de cada departamento, inactivos, borrados, sin correo, sin grado y con nombres muy largos', function () {
    $teachers = collect(roster(60));

    expect($teachers->pluck('adscriptions.0.dept')->unique()->sort()->values()->all())->toBe([0, 1, 2, 3])
        ->and($teachers->contains(fn (array $teacher) => ! $teacher['is_active']))->toBeTrue()
        ->and($teachers->contains(fn (array $teacher) => $teacher['deleted']))->toBeTrue()
        ->and($teachers->contains(fn (array $teacher) => $teacher['email'] === null))->toBeTrue()
        ->and($teachers->contains(fn (array $teacher) => $teacher['degree'] === null))->toBeTrue()
        ->and($teachers->contains(fn (array $teacher) => mb_strlen($teacher['name']) > 80))->toBeTrue();
});

it('la disponibilidad cae entre las 7:00 y las 21:00 de lunes a viernes, sin traslapes y con los contiguos unidos', function () {
    $blocks = collect(roster(60))->pluck('availability')->filter();

    expect($blocks->isNotEmpty())->toBeTrue();

    foreach ($blocks as $teacherBlocks) {
        $byDay = collect($teacherBlocks)->groupBy('day');

        foreach ($byDay as $day => $dayBlocks) {
            expect($day)->toBeBetween(1, 5);
            $sorted = $dayBlocks->sortBy('starts_at')->values();

            foreach ($sorted as $i => $block) {
                expect($block['starts_at'])->toBeGreaterThanOrEqual('07:00')
                    ->and($block['ends_at'])->toBeLessThanOrEqual('21:00')
                    ->and($block['ends_at'])->toBeGreaterThan($block['starts_at']);

                if ($i > 0) {
                    $previous = $sorted[$i - 1];
                    expect($block['starts_at'])->toBeGreaterThanOrEqual($previous['ends_at']);

                    if ($block['starts_at'] === $previous['ends_at']) {
                        expect($block['kind'])->not->toBe($previous['kind']);
                    }
                }
            }
        }
    }
});

it('el historial tiene 8 periodos del SII, una fila por grupo, y evaluaciones de 1 a 5 de los dos tipos', function () {
    $teachers = collect(roster(60));
    $history = $teachers->flatMap(fn (array $teacher) => $teacher['history']);
    $evaluations = $teachers->flatMap(fn (array $teacher) => $teacher['evaluations']);

    expect($history->pluck('term')->unique()->count())->toBe(8)
        ->and($history->every(fn (array $row) => preg_match('/^[0-9]{4}[123]$/', $row['term']) === 1))->toBeTrue()
        ->and($evaluations->pluck('kind')->unique()->sort()->values()->all())->toBe(['departamental', 'estudiantil'])
        ->and($evaluations->every(fn (array $row) => $row['score'] >= 1 && $row['score'] <= 5))->toBeTrue();

    foreach ($teachers as $teacher) {
        $keys = array_map(fn (array $row) => "{$row['term']}|{$row['subject']}|{$row['group']}", $teacher['history']);
        expect(array_unique($keys))->toHaveCount(count($keys));
    }

    expect($teachers->contains(fn (array $teacher) => collect($teacher['history'])->groupBy(fn (array $row) => "{$row['term']}|{$row['subject']}")->contains(fn ($rows) => $rows->count() > 1)))->toBeTrue();
});

it('no da perfil ni historial de materias de un departamento sin materias', function () {
    foreach (roster(60) as $teacher) {
        if ($teacher['adscriptions'][0]['dept'] === 2) {
            expect($teacher['profile'])->toBe([])->and($teacher['history'])->toBe([]);
        }
    }
});
