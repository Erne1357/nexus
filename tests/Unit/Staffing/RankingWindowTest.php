<?php

use App\Modules\Staffing\Rules\RankingWindow;

/*
| Ranking docente (desempate), reglas-de-negocio.md: el historial son los últimos N semestres (Ene-Jun y Ago-Dic)
| antes del periodo; los grupos de verano no cuentan (confirmado el 7 de noviembre de 2026).
*/

it('Ranking docente: da los últimos N semestres antes del periodo, sin veranos', function (string $term, int $count, array $expected) {
    expect(RankingWindow::semestersBefore($term, $count))->toBe($expected);
})->with([
    'seis desde Ene-Jun' => ['20271', 6, ['20263', '20261', '20253', '20251', '20243', '20241']],
    'dos desde Ene-Jun' => ['20271', 2, ['20263', '20261']],
    'desde verano' => ['20272', 2, ['20271', '20263']],
    'desde Ago-Dic' => ['20263', 3, ['20261', '20253', '20251']],
    'cruza el año' => ['20261', 1, ['20253']],
    'cero' => ['20271', 0, []],
]);

it('Ranking docente: rechaza un código de periodo inválido', function (string $term) {
    RankingWindow::semestersBefore($term, 2);
})->with(['2027', '20274', '20270', 'abcde', '202711'])->throws(InvalidArgumentException::class);
