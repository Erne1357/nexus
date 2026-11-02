<?php

use App\Modules\Core\Models\Term;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Validation\ValidationException;

/*
| TermRules: código `AAAAP` único y fechas en orden, con errores en español sobre su campo.
*/

it('acepta y recorta un código AAAAP', function () {
    expect(app(TermRules::class)->normalizeCode(' 20271 '))->toBe('20271');
});

it('rechaza un código que no tiene la forma AAAAP, sobre el campo code', function (string $code) {
    try {
        app(TermRules::class)->normalizeCode($code);
        $this->fail('Debía rechazar el código.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toHaveKey('code')
            ->and($exception->errors()['code'][0])->toContain('El código lleva el año y el periodo');
    }
})->with(['20270', '20274', '2027', '202711', 'ABCD1', '']);

it('rechaza un código repetido y deja pasar al periodo que lo conserva', function () {
    $term = Term::factory()->create(['code' => '20271']);

    expect(fn () => app(TermRules::class)->ensureCodeIsFree('20271'))
        ->toThrow(ValidationException::class, 'Ya existe un periodo con el código 20271.');

    app(TermRules::class)->ensureCodeIsFree('20271', $term);
    app(TermRules::class)->ensureCodeIsFree('20272');
    expect(true)->toBeTrue();
});

it('rechaza que el fin sea anterior al inicio, sobre ends_on, y deja pasar el mismo día', function () {
    expect(app(TermRules::class)->dates('2027-01-18', '2027-01-18'))->toBe(['2027-01-18', '2027-01-18']);

    try {
        app(TermRules::class)->dates('2027-06-11', '2027-01-18');
        $this->fail('Debía rechazar las fechas.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['ends_on' => ['La fecha de fin no puede ser anterior a la de inicio.']]);
    }
});

it('rechaza una fecha que no es fecha, sobre su campo', function () {
    expect(fn () => app(TermRules::class)->dates('no-es-fecha', '2027-01-18'))->toThrow(ValidationException::class, 'La fecha no es válida.');
});
