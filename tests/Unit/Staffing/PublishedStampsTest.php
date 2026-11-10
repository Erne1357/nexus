<?php

use App\Modules\Staffing\Support\PublishedStamps;
use Carbon\CarbonImmutable;

/*
| La línea «Publicado el … · vN» de «Mi carga» y la oferta (P8): una por carga publicada de las que se ven. Si la
| pantalla muestra más de una carga, cada una dice de cuál: el departamento si hay varios, el campus si hay varios.
*/

/**
 * @return array{department: string, campus: string, published: ?array{version: int, at: CarbonImmutable}}
 */
function publishedStampsLoad(string $department, string $campus, ?int $version = null, string $at = '2026-12-08 10:00'): array
{
    return ['department' => $department, 'campus' => $campus, 'published' => $version === null ? null : ['version' => $version, 'at' => CarbonImmutable::parse($at)]];
}

/**
 * @param  list<array{scope: ?string, version: int, published_at: CarbonImmutable}>  $stamps
 * @return list<string>
 */
function publishedStampsTexts(array $stamps): array
{
    return array_map(fn (array $stamp): string => ($stamp['scope'] ?? '—').' v'.$stamp['version'].' '.$stamp['published_at']->format('Y-m-d'), $stamps);
}

it('sin cargas publicadas no hay línea', function () {
    expect(PublishedStamps::of([publishedStampsLoad('Sistemas', 'C2'), publishedStampsLoad('Sistemas', 'C1')]))->toBe([]);
});

it('con una sola carga a la vista, la línea no dice de cuál es, aunque se repita en varios grupos', function () {
    expect(publishedStampsTexts(PublishedStamps::of([publishedStampsLoad('Sistemas', 'C2', 2), publishedStampsLoad('Sistemas', 'C2', 2)])))
        ->toBe(['— v2 2026-12-08']);
});

it('con varias cargas dice el campus si varían los campus y el departamento si varían los departamentos', function () {
    expect(publishedStampsTexts(PublishedStamps::of([publishedStampsLoad('Sistemas', 'C2', 1), publishedStampsLoad('Sistemas', 'C1')])))
        ->toBe(['C2 v1 2026-12-08'])
        ->and(publishedStampsTexts(PublishedStamps::of([publishedStampsLoad('Sistemas', 'C2', 1), publishedStampsLoad('Ciencias Básicas', 'C2', 3, '2026-12-11 09:00')])))
        ->toBe(['Ciencias Básicas v3 2026-12-11', 'Sistemas v1 2026-12-08'])
        ->and(publishedStampsTexts(PublishedStamps::of([publishedStampsLoad('Sistemas', 'C2', 1), publishedStampsLoad('Ciencias Básicas', 'C1', 2)])))
        ->toBe(['Ciencias Básicas en C1 v2 2026-12-08', 'Sistemas en C2 v1 2026-12-08']);
});
