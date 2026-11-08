<?php

use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Staffing\Queries\RankingRow;

/*
| Cómo se lee un lugar del ranking en la asignación: el puntaje y la evaluación sin ceros de sobra, «Quedan 12 → 7»
| cuando hay horas con y sin el grupo, y las marcas que lo bajan al bloque final.
*/

function rankingRow(array $with = []): RankingRow
{
    return new RankingRow(...[
        'rank' => 1, 'score' => 15.0, 'teacherId' => 't', 'teacherName' => 'Ana Ruiz', 'positions' => 'TC', 'baseHours' => 40,
        'historyCount' => 3, 'qualification' => QualificationLevel::Preferente, 'evaluation' => 4.5, 'remainingHours' => 12,
        'remainingAfterHours' => 7, 'flags' => [], 'fromOtherDepartment' => false, ...$with,
    ]);
}

it('escribe el puntaje y la evaluación sin ceros de sobra', function () {
    expect(rankingRow()->scoreLabel())->toBe('15')
        ->and(rankingRow(['score' => 9.66])->scoreLabel())->toBe('9.66')
        ->and(rankingRow(['evaluation' => 4.333])->evaluationLabel())->toBe('4.3')
        ->and(rankingRow(['evaluation' => null])->evaluationLabel())->toBeNull();
});

it('dice las horas que quedan con y sin el grupo, solo las de sin grupo o ninguna', function () {
    expect(rankingRow()->remainingLabel())->toBe('12 → 7')
        ->and(rankingRow(['remainingAfterHours' => null])->remainingLabel())->toBe('12')
        ->and(rankingRow(['remainingHours' => null, 'remainingAfterHours' => null])->remainingLabel())->toBeNull();
});

it('las marcas del bloque final son sin horas, choque y no disponible; sin evaluación no baja', function () {
    $flagged = rankingRow(['flags' => ['choque', 'sin_evaluacion', 'sin_horas']]);

    expect($flagged->isFlagged())->toBeTrue()
        ->and($flagged->marks())->toBe(['sin_horas' => 'Sin horas', 'choque' => 'Choque'])
        ->and(rankingRow(['flags' => ['sin_evaluacion'], 'evaluation' => null])->isFlagged())->toBeFalse();
});

it('SC-09: «traslado justo» es una marca discreta que no baja al bloque final', function () {
    $row = rankingRow(['flags' => ['traslado_justo']]);

    expect($row->isFlagged())->toBeFalse()
        ->and($row->marks())->toBe([])
        ->and($row->notices())->toBe(['traslado_justo' => 'traslado justo'])
        ->and(rankingRow()->notices())->toBe([]);
});
