<?php

use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Enums\StageSource;
use App\Modules\Core\Enums\StageStatus;
use App\Modules\Core\Queries\StageWindow;
use App\Modules\Core\Support\StageTimeline;
use Carbon\CarbonImmutable;

/*
| La línea de tiempo de las etapas de un periodo (DESIGN.md, "Línea de tiempo de etapas"): del lunes de la
| primera semana con fechas al domingo de la última, con una columna por semana, una barra general por etapa
| y una barra delgada por cada campus con ventana propia.
*/

function timelineWindow(Stage $stage, ?string $from, ?string $to, StageSource $source = StageSource::General): StageWindow
{
    return new StageWindow(
        $stage,
        $from === null ? null : CarbonImmutable::parse($from),
        $to === null ? null : CarbonImmutable::parse($to),
        $from === null ? StageSource::None : $source,
        $from === null ? StageStatus::Unscheduled : StageStatus::Upcoming,
        null,
    );
}

/**
 * @return list<StageWindow>
 */
function timelineGeneral(): array
{
    return [
        timelineWindow(Stage::CatalogValidation, '2026-11-10', '2026-11-19'),
        timelineWindow(Stage::Proposal, null, null),
        timelineWindow(Stage::Scheduling, '2026-11-20', '2026-12-04'),
    ];
}

it('redondea el rango a semanas: del lunes anterior al primer inicio al domingo posterior al último fin', function () {
    $timeline = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->from->toDateString())->toBe('2026-11-09')
        ->and($timeline->to->toDateString())->toBe('2026-12-06')
        ->and($timeline->days)->toBe(28)
        ->and(array_map(fn (CarbonImmutable $week): string => $week->toDateString(), $timeline->weeks))
        ->toBe(['2026-11-09', '2026-11-16', '2026-11-23', '2026-11-30']);
});

it('un inicio en lunes y un fin en domingo no agregan semanas', function () {
    $timeline = StageTimeline::build([timelineWindow(Stage::Proposal, '2026-11-09', '2026-11-15')], [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->from->toDateString())->toBe('2026-11-09')
        ->and($timeline->to->toDateString())->toBe('2026-11-15')
        ->and($timeline->days)->toBe(7);
});

it('calcula la posición y el ancho de cada barra como porcentaje del rango, contando el último día', function () {
    $timeline = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->rows[0]['left'])->toEqualWithDelta(1 / 28 * 100, 0.001)
        ->and($timeline->rows[0]['width'])->toEqualWithDelta(10 / 28 * 100, 0.001)
        ->and($timeline->rows[2]['left'])->toEqualWithDelta(11 / 28 * 100, 0.001)
        ->and($timeline->rows[2]['width'])->toEqualWithDelta(15 / 28 * 100, 0.001)
        ->and($timeline->rows[0]['window']->stage)->toBe(Stage::CatalogValidation);
});

it('una etapa sin fechas queda en su lugar, sin barra', function () {
    $timeline = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->rows)->toHaveCount(3)
        ->and($timeline->rows[1])->left->toBeNull()->width->toBeNull()->campuses->toBe([]);
});

it('agrega una barra delgada por cada campus con ventana propia y ensancha el rango si hace falta', function () {
    $timeline = StageTimeline::build(timelineGeneral(), [
        'C2' => [timelineWindow(Stage::Scheduling, '2026-11-24', '2026-12-09', StageSource::Campus)],
        'C1' => [],
    ], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->to->toDateString())->toBe('2026-12-13')
        ->and($timeline->days)->toBe(35)
        ->and($timeline->rows[2]['campuses'])->toHaveCount(1)
        ->and($timeline->rows[2]['campuses'][0]['code'])->toBe('C2')
        ->and($timeline->rows[2]['campuses'][0]['left'])->toEqualWithDelta(15 / 35 * 100, 0.001)
        ->and($timeline->rows[2]['campuses'][0]['width'])->toEqualWithDelta(16 / 35 * 100, 0.001)
        ->and($timeline->rows[0]['campuses'])->toBe([]);
});

it('ubica la marca de hoy a media columna del día cuando cae dentro del rango', function () {
    $in = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-12'));
    $before = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-08'));
    $after = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-12-07'));
    $edge = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-12-06'));

    expect($in->today)->toEqualWithDelta(3.5 / 28 * 100, 0.001)
        ->and($before->today)->toBeNull()
        ->and($after->today)->toBeNull()
        ->and($edge->today)->not->toBeNull();
});

it('rotula los meses del rango con los días que cubre cada uno', function () {
    $timeline = StageTimeline::build(timelineGeneral(), [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->months)->toBe([
        ['label' => 'Nov 2026', 'days' => 22],
        ['label' => 'Dic 2026', 'days' => 6],
    ]);
});

it('sin ninguna fecha no hay rango, ni semanas, ni marca de hoy', function () {
    $timeline = StageTimeline::build([timelineWindow(Stage::Proposal, null, null)], [], CarbonImmutable::parse('2026-11-12'));

    expect($timeline->from)->toBeNull()
        ->and($timeline->to)->toBeNull()
        ->and($timeline->days)->toBe(0)
        ->and($timeline->weeks)->toBe([])
        ->and($timeline->months)->toBe([])
        ->and($timeline->today)->toBeNull()
        ->and($timeline->hasRange())->toBeFalse()
        ->and($timeline->rows)->toHaveCount(1);
});

it('escribe el rango de fechas de una ventana en español', function () {
    expect(StageTimeline::rangeLabel(timelineWindow(Stage::Proposal, '2026-11-10', '2026-11-19')))->toBe('10–19 nov')
        ->and(StageTimeline::rangeLabel(timelineWindow(Stage::Proposal, '2026-11-10', '2026-11-10')))->toBe('10 nov')
        ->and(StageTimeline::rangeLabel(timelineWindow(Stage::Proposal, '2026-11-20', '2026-12-04')))->toBe('20 nov – 4 dic')
        ->and(StageTimeline::rangeLabel(timelineWindow(Stage::Proposal, '2026-12-28', '2027-01-08')))->toBe('28 dic 2026 – 8 ene 2027')
        ->and(StageTimeline::rangeLabel(timelineWindow(Stage::Proposal, null, null)))->toBe('Sin fecha');
});

it('dice cuántos días faltan según el estado de la etapa', function (StageStatus $status, ?int $days, ?string $label) {
    $window = new StageWindow(Stage::Proposal, CarbonImmutable::parse('2026-11-10'), CarbonImmutable::parse('2026-11-19'), StageSource::General, $status, $days);

    expect(StageTimeline::daysLeftLabel($window))->toBe($label);
})->with([
    'en curso, quedan 7' => [StageStatus::InProgress, 7, 'quedan 7 días'],
    'en curso, queda 1' => [StageStatus::InProgress, 1, 'queda 1 día'],
    'en curso, último día' => [StageStatus::InProgress, 0, 'último día'],
    'próxima, en 5' => [StageStatus::Upcoming, 5, 'empieza en 5 días'],
    'próxima, mañana' => [StageStatus::Upcoming, 1, 'empieza mañana'],
    'terminada' => [StageStatus::Finished, null, null],
    'sin fecha' => [StageStatus::Unscheduled, null, null],
]);
