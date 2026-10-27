<?php

use App\Modules\Imports\Templates\RowIssues;

it('empieza sin errores ni advertencias', function () {
    $issues = new RowIssues;

    expect($issues->hasErrors())->toBeFalse()
        ->and($issues->hasWarnings())->toBeFalse();
});

it('acumula errores y advertencias por campo sin cambiar la instancia original', function () {
    $empty = new RowIssues;

    $issues = $empty
        ->withError('code', 'Primero.')
        ->withError('code', 'Segundo.')
        ->withWarning('name', 'Ojo.');

    expect($issues->errors)->toBe(['code' => ['Primero.', 'Segundo.']])
        ->and($issues->warnings)->toBe(['name' => ['Ojo.']])
        ->and($issues->hasErrors())->toBeTrue()
        ->and($issues->hasWarnings())->toBeTrue()
        ->and($empty->hasErrors())->toBeFalse();
});
