<?php

use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Staffing\Rules\RankedCandidate;
use App\Modules\Staffing\Rules\RankingCandidate;
use App\Modules\Staffing\Rules\RankingScorer;
use App\Modules\Staffing\Rules\RankingWeights;

/*
| Ranking docente (desempate), reglas-de-negocio.md: horas base, puntaje, orden del periodo anterior, nombre, id;
| los marcados (sin horas, choque, no disponible) van al final.
*/

function rankingCandidate(string $id, array $with = []): RankingCandidate
{
    return new RankingCandidate(...[
        'teacherId' => $id, 'name' => "Docente {$id}", 'baseHours' => 20, 'historyCount' => 0, 'qualification' => null,
        'evaluation' => 4.0, 'remainingHours' => 10, 'noHours' => false, 'clash' => false, 'unavailable' => false,
        'previousRank' => null, ...$with,
    ]);
}

/**
 * @param  list<RankingCandidate>  $candidates
 * @return list<string>
 */
function rankedIds(array $candidates, ?RankingWeights $weights = null): array
{
    return array_map(fn (RankedCandidate $r) => $r->candidate->teacherId, (new RankingScorer($weights ?? new RankingWeights))->rank($candidates));
}

it('Ranking docente: el puntaje suma historial, perfil y evaluación con los pesos', function () {
    $ranked = (new RankingScorer(new RankingWeights))->rank([
        rankingCandidate('a', ['historyCount' => 3, 'qualification' => QualificationLevel::Preferente, 'evaluation' => 4.5]),
        rankingCandidate('b', ['historyCount' => 2, 'qualification' => QualificationLevel::Apto, 'evaluation' => 3.33]),
        rankingCandidate('c', ['evaluation' => null]),
    ]);

    expect($ranked[0]->score)->toBe(15.0) // 3 + 3 + 9
        ->and($ranked[1]->score)->toBe(9.66) // 2 + 1 + 6.66
        ->and($ranked[2]->score)->toBe(0.0);
});

it('Ranking docente: respeta pesos propios', function () {
    $weights = new RankingWeights(perGroupTaught: 2, preferente: 10, apto: 0.5, evaluation: 0);
    $ranked = (new RankingScorer($weights))->rank([rankingCandidate('a', ['historyCount' => 2, 'qualification' => QualificationLevel::Apto])]);

    expect($ranked[0]->score)->toBe(4.5);
});

it('Ranking docente: el perfil preferente reemplaza al apto, no se suman', function () {
    $ranked = (new RankingScorer(new RankingWeights))->rank([rankingCandidate('a', ['qualification' => QualificationLevel::Preferente, 'evaluation' => null])]);

    expect($ranked[0]->score)->toBe(3.0);
});

it('Ranking docente: las horas base van primero, aunque el puntaje sea menor', function () {
    expect(rankedIds([
        rankingCandidate('a', ['baseHours' => 10, 'historyCount' => 9]),
        rankingCandidate('b', ['baseHours' => 40]),
        rankingCandidate('c', ['baseHours' => 20]),
    ]))->toBe(['b', 'c', 'a']);
});

it('Ranking docente: con las mismas horas base manda el puntaje', function () {
    expect(rankedIds([
        rankingCandidate('a', ['historyCount' => 1]),
        rankingCandidate('b', ['historyCount' => 5]),
        rankingCandidate('c', ['historyCount' => 3]),
    ]))->toBe(['b', 'c', 'a']);
});

it('Ranking docente: con el mismo puntaje conserva el orden del periodo anterior, sin orden al final', function () {
    expect(rankedIds([
        rankingCandidate('a'),
        rankingCandidate('b', ['previousRank' => 2]),
        rankingCandidate('c', ['previousRank' => 1]),
    ]))->toBe(['c', 'b', 'a']);
});

it('Ranking docente: con todo empatado ordena por nombre sin importar acentos ni mayúsculas', function () {
    expect(rankedIds([
        rankingCandidate('1', ['name' => 'Bañuelos']),
        rankingCandidate('2', ['name' => 'Álvarez']),
        rankingCandidate('3', ['name' => 'alvarado']),
    ]))->toBe(['3', '2', '1']);
});

it('Ranking docente: el último recurso es el id del docente', function () {
    expect(rankedIds([
        rankingCandidate('b', ['name' => 'Igual']),
        rankingCandidate('a', ['name' => 'igual']),
    ]))->toBe(['a', 'b']);
});

it('Ranking docente: sin horas, choque y no disponible van juntos al final, ordenados entre sí con los mismos criterios', function () {
    expect(rankedIds([
        rankingCandidate('flag-low', ['noHours' => true, 'baseHours' => 10]),
        rankingCandidate('ok-low', ['baseHours' => 5]),
        rankingCandidate('flag-high', ['clash' => true, 'baseHours' => 40]),
        rankingCandidate('flag-un', ['unavailable' => true, 'baseHours' => 20]),
        rankingCandidate('ok-high', ['baseHours' => 8]),
    ]))->toBe(['ok-high', 'ok-low', 'flag-high', 'flag-un', 'flag-low']);
});

it('Ranking docente: asigna posiciones de 1 a n', function () {
    $ranked = (new RankingScorer(new RankingWeights))->rank([rankingCandidate('a'), rankingCandidate('b', ['baseHours' => 30])]);

    expect(array_map(fn (RankedCandidate $r) => $r->rank, $ranked))->toBe([1, 2]);
});

it('Ranking docente: el resultado no depende del orden de entrada', function () {
    $candidates = [];
    foreach (range(1, 12) as $i) {
        $candidates[] = rankingCandidate((string) $i, [
            'name' => ['Ana', 'Ána', 'Beto'][$i % 3], 'baseHours' => [10, 20][$i % 2], 'historyCount' => $i % 4,
            'previousRank' => $i % 5 === 0 ? null : $i % 3, 'clash' => $i % 7 === 0,
        ]);
    }
    $expected = rankedIds($candidates);

    foreach (range(1, 10) as $seed) {
        mt_srand($seed);
        shuffle($candidates);
        expect(rankedIds($candidates))->toBe($expected);
    }
});

it('Ranking docente: el desglose lista datos y marcas', function () {
    $ranked = (new RankingScorer(new RankingWeights))->rank([
        rankingCandidate('a', ['qualification' => QualificationLevel::Apto, 'evaluation' => null, 'remainingHours' => 2, 'noHours' => true, 'clash' => true, 'unavailable' => true, 'historyCount' => 1, 'baseHours' => 18]),
        rankingCandidate('b', ['evaluation' => 4.25, 'remainingHours' => null]),
    ]);

    expect($ranked[1]->breakdown())->toBe([
        'base_hours' => 18, 'history_count' => 1, 'qualification' => 'apto', 'evaluation' => null, 'remaining_hours' => 2,
        'flags' => ['sin_horas', 'choque', 'no_disponible', 'sin_evaluacion'],
    ])->and($ranked[0]->breakdown())->toBe([
        'base_hours' => 20, 'history_count' => 0, 'qualification' => null, 'evaluation' => 4.25, 'remaining_hours' => null, 'flags' => [],
    ]);
});

it('SC-09: el traslado justo queda en las marcas del desglose sin bajar al docente', function () {
    $ranked = (new RankingScorer(new RankingWeights))->rank([
        rankingCandidate('a', ['baseHours' => 40, 'tightTravel' => true]),
        rankingCandidate('b', ['baseHours' => 20]),
    ]);

    expect($ranked[0]->candidate->teacherId)->toBe('a')
        ->and($ranked[0]->breakdown()['flags'])->toBe(['traslado_justo']);
});
