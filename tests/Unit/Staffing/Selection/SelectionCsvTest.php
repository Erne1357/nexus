<?php

use App\Modules\Staffing\Rules\SelectionCsv;

it('SEL-06: el CSV se lee con BOM y encabezado con acentos o mayúsculas', function () {
    $csv = "\xEF\xBB\xBFNOMBRE COMPLETO;Horas;Auto Ev;Cumpl Asig;C1;C2;Antigüedad\n"
        ."José Pérez;40;sí;no;1;X;12,5\n"
        ."Ana Ejemplo;20;no;;0;si;3.25\n";

    $result = SelectionCsv::parse($csv);

    expect($result['errors'])->toBe([])
        ->and($result['rows'])->toBe([
            ['line' => 2, 'name' => 'José Pérez', 'hours' => 40, 'auto_eval' => true, 'prior_compliance' => false, 'partials_1' => true, 'partials_2' => true, 'seniority_years' => 12.5],
            ['line' => 3, 'name' => 'Ana Ejemplo', 'hours' => 20, 'auto_eval' => false, 'prior_compliance' => false, 'partials_1' => false, 'partials_2' => true, 'seniority_years' => 3.25],
        ]);
});

it('SEL-06: un renglón con horas no numéricas va a errores con su línea', function () {
    $csv = "Nombre completo;horas;auto ev;cumpl asig;C1;C2;antiguedad\n"
        ."Ana Ejemplo;mucho;si;si;si;si;1\n"
        ."Luis Ejemplo;30;si;si;si;si;2\n";

    $result = SelectionCsv::parse($csv);

    expect($result['rows'])->toHaveCount(1)
        ->and($result['errors'])->toHaveCount(1)
        ->and($result['errors'][0]['line'])->toBe(2);
});

it('SEL-06: un renglón con antigüedad inválida o sin nombre va a errores', function () {
    $csv = "Nombre completo;horas;auto ev;cumpl asig;C1;C2;antiguedad\n"
        .";30;si;si;si;si;2\n"
        ."Luis Ejemplo;30;si;si;si;si;poca\n";

    $result = SelectionCsv::parse($csv);

    expect($result['rows'])->toBe([])
        ->and(array_column($result['errors'], 'line'))->toBe([2, 3]);
});
