<?php

use App\Modules\Core\Enums\StatusTone;
use App\Modules\Staffing\Enums\RankingMark;

/*
| Las marcas de un docente en el ranking (reglas-de-negocio.md, «Ranking docente (desempate)»): el mismo nombre, tono e
| icono en la asignación y en la oferta del docente. Choque y no disponible lo bajan; pasar su banda (HC-08) es aviso.
*/

it('cada marca tiene el nombre, el tono y el icono de la asignación', function (RankingMark $mark, string $label, StatusTone $tone, string $icon) {
    expect($mark->label())->toBe($label)
        ->and($mark->tone())->toBe($tone)
        ->and($mark->icon())->toBe($icon);
})->with([
    'choque (HC-01)' => [RankingMark::Clash, 'Choque', StatusTone::Error, 'o-x-circle'],
    'pasa su banda (HC-08)' => [RankingMark::NoHours, 'Pasa su banda', StatusTone::Warning, 'o-clock'],
    'no disponible (HC-09)' => [RankingMark::Unavailable, 'No disponible', StatusTone::Draft, 'o-no-symbol'],
]);

it('lee las marcas de las banderas del desglose, en su orden, sin «sin evaluación» ni las que no conoce', function () {
    expect(RankingMark::fromFlags(['sin_evaluacion', 'no_disponible', 'otra', 'choque']))->toBe([RankingMark::Clash, RankingMark::Unavailable])
        ->and(RankingMark::fromFlags([]))->toBe([]);
});

it('HC-08: pasar su banda es aviso y no baja; choque y no disponible sí (tarea 1.50)', function () {
    expect(RankingMark::NoHours->lowers())->toBeFalse()
        ->and(RankingMark::Clash->lowers())->toBeTrue()
        ->and(RankingMark::Unavailable->lowers())->toBeTrue();
});
