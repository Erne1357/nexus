<?php

use App\Modules\Students\Database\Seeders\TrajectorySimulator;
use Random\Engine\Mt19937;
use Random\Randomizer;

/*
| Simulador de trayectorias de los seeders demo y masivo (tarea 1.8, PR 3). Clase pura, sin base: con una
| semilla fija da el kardex de un alumno periodo por periodo. Reproduce lo que dicen "Elegibilidad y demanda" y
| "Retícula del alumno" de reglas-de-negocio.md: seriación, oportunidades 1 a 3, bajas, especialidad.
*/

/**
 * Una retícula chica: 4 semestres de 3 materias en cadenas y dos especialidades de 2 materias en el 4.
 *
 * @return array{semesters: array<int, list<string>>, requires: array<string, list<string>>, specialtyOf: array<string, string>}
 */
function simulatorGrid(): array
{
    return [
        'semesters' => [
            1 => ['a1', 'b1', 'c1'],
            2 => ['a2', 'b2', 'c2'],
            3 => ['a3', 'b3', 'c3'],
            4 => ['a4', 'b4', 'x1', 'x2', 'y1', 'y2'],
        ],
        'requires' => ['a2' => ['a1'], 'a3' => ['a2'], 'a4' => ['a3'], 'b2' => ['b1'], 'b3' => ['b2'], 'x2' => ['x1']],
        'specialtyOf' => ['x1' => 'X', 'x2' => 'X', 'y1' => 'Y', 'y2' => 'Y'],
    ];
}

/**
 * @param  array{ability?: float, status?: ?string, ungraded?: float}  $profile
 * @return list<array{subject_id: string, term_code: string, grade: ?int, result: string, attempt: int}>
 */
function simulateTrajectory(int $seed, array $profile = [], string $admitted = '20231', string $current = '20263'): array
{
    return (new TrajectorySimulator(new Randomizer(new Mt19937($seed))))
        ->simulate(simulatorGrid(), $admitted, $current, ['ability' => 0.6, 'status' => 'activo', ...$profile]);
}

it('nunca cursa una materia sin haber aprobado antes su seriación', function (int $seed) {
    $passed = [];
    $byTerm = collect(simulateTrajectory($seed, ['ability' => 0.4]))->groupBy('term_code')->sortKeys();

    foreach ($byTerm as $rows) {
        foreach ($rows as $row) {
            foreach (simulatorGrid()['requires'][$row['subject_id']] ?? [] as $required) {
                expect($passed)->toHaveKey($required);
            }
        }

        foreach ($rows->where('result', 'aprobada') as $row) {
            $passed[$row['subject_id']] = true;
        }
    }
})->with(range(1, 25));

it('las oportunidades de una materia van 1, 2, 3 en periodos crecientes y se detiene al aprobar', function (int $seed) {
    $rows = collect(simulateTrajectory($seed, ['ability' => 0.3, 'ungraded' => 0.1]))->where('result', '!=', 'baja')->groupBy('subject_id');

    foreach ($rows as $attempts) {
        $attempts = $attempts->sortBy('term_code')->values();

        expect($attempts->pluck('attempt')->all())->toBe(range(1, $attempts->count()))
            ->and($attempts->pluck('term_code')->unique()->count())->toBe($attempts->count())
            ->and($attempts->count())->toBeLessThanOrEqual(3)
            ->and($attempts->slice(0, -1)->pluck('result')->all())->not->toContain('aprobada');
    }
})->with(range(1, 25));

it('una baja no gasta oportunidad', function () {
    $bajas = 0;

    foreach (range(1, 60) as $seed) {
        $rows = collect(simulateTrajectory($seed))->groupBy('subject_id');

        foreach ($rows as $subjectRows) {
            $sorted = $subjectRows->sortBy('term_code')->values();

            foreach ($sorted as $i => $row) {
                if ($row['result'] === 'baja') {
                    $bajas++;
                    expect($row['grade'])->toBeNull()
                        ->and($row['attempt'])->toBe($sorted->slice(0, $i)->where('result', '!=', 'baja')->count() + 1);
                }
            }
        }
    }

    expect($bajas)->toBeGreaterThan(0);
});

it('solo el periodo en curso tiene materias cursando', function (int $seed) {
    $rows = collect(simulateTrajectory($seed, admitted: '20251'));

    expect($rows->where('result', 'cursando')->isNotEmpty())->toBeTrue()
        ->and($rows->where('result', 'cursando')->pluck('term_code')->unique()->all())->toBe(['20263'])
        ->and($rows->where('result', 'cursando')->pluck('grade')->filter()->all())->toBe([]);
})->with(range(1, 10));

it('deja materias sin calificar en periodos anteriores solo si el perfil lo pide', function () {
    $rows = collect(simulateTrajectory(3, ['ungraded' => 0.5]));

    expect($rows->where('result', 'cursando')->where('term_code', '!=', '20263')->isNotEmpty())->toBeTrue();
});

it('tras elegir especialidad no cursa materias de otra', function (int $seed) {
    $specialtyOf = simulatorGrid()['specialtyOf'];
    $chosen = collect(simulateTrajectory($seed, ['ability' => 0.9]))->pluck('subject_id')->map(fn (string $id) => $specialtyOf[$id] ?? null)->filter()->unique();

    expect($chosen->count())->toBeLessThanOrEqual(1);
})->with(range(1, 25));

it('la misma semilla da el mismo kárdex', function () {
    expect(simulateTrajectory(11))->toBe(simulateTrajectory(11))
        ->and(simulateTrajectory(11))->not->toBe(simulateTrajectory(12));
});

it('un egresado tiene acreditada toda su retícula con una especialidad', function (int $seed) {
    $rows = collect(simulateTrajectory($seed, ['status' => 'egresado'], '20201'));
    $approved = $rows->where('result', 'aprobada')->pluck('subject_id');
    $specialtyOf = simulatorGrid()['specialtyOf'];
    $common = collect(simulatorGrid()['semesters'])->flatten()->reject(fn (string $id) => isset($specialtyOf[$id]));

    expect($approved->intersect($common)->unique()->count())->toBe($common->count())
        ->and($approved->map(fn (string $id) => $specialtyOf[$id] ?? null)->filter()->unique()->count())->toBe(1)
        ->and($rows->pluck('term_code')->max())->toBeLessThan('20263')
        ->and($rows->where('result', 'cursando')->isEmpty())->toBeTrue();
})->with(range(1, 15));

it('reprobar el curso especial termina la trayectoria', function () {
    foreach (range(1, 80) as $seed) {
        $rows = collect(simulateTrajectory($seed, ['ability' => 0.0]));
        $last = $rows->sortBy('term_code')->last();

        if ($last !== null && $last['result'] === 'reprobada' && $last['attempt'] === 3) {
            expect($rows->where('term_code', '>', $last['term_code']))->toBeEmpty();

            return;
        }
    }

    $this->fail('Ninguna semilla llegó a reprobar el curso especial.');
});

it('un alumno de baja admitido en el periodo en curso no cursa nada', function (string $status) {
    expect(simulateTrajectory(5, ['status' => $status], '20263'))->toBe([]);
})->with(['baja_temporal', 'baja_definitiva', 'egresado']);
