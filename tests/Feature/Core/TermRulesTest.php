<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
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

it('rechaza fechas vacías, relativas, inexistentes o con otro formato, sobre su campo', function (mixed $value, string $message) {
    try {
        app(TermRules::class)->date('starts_on', $value);
        $this->fail('Debía rechazar la fecha.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['starts_on' => [$message]]);
    }
})->with([
    'nulo' => [null, 'Escribe la fecha.'],
    'vacío' => ['', 'Escribe la fecha.'],
    'now' => ['now', 'La fecha no es válida.'],
    '30 de febrero' => ['2027-02-30', 'La fecha no es válida.'],
    'día/mes/año' => ['30/01/2027', 'La fecha no es válida.'],
    'número' => [20270130, 'La fecha no es válida.'],
]);

it('acepta una fecha Y-m-d o un DateTimeInterface', function () {
    expect(app(TermRules::class)->date('starts_on', '2027-02-28'))->toBe('2027-02-28')
        ->and(app(TermRules::class)->date('starts_on', new DateTimeImmutable('2027-03-01 10:00')))->toBe('2027-03-01');
});

it('explica por qué el código no cambia, con los campus donde se abrió, también uno desactivado', function () {
    $term = Term::factory()->create();
    $rules = app(TermRules::class);

    expect($rules->codeLockReason($term))->toBeNull();

    TermOpening::factory()->for($term)->for(Campus::factory()->create(['code' => 'C2']))->create();
    expect($rules->codeLockReason($term))->toBe('El código ya no se puede cambiar: el periodo se abrió en C2.');

    TermOpening::factory()->for($term)->for(Campus::factory()->inactive()->create(['code' => 'C1']))->create();
    TermOpening::factory()->for($term)->for(Campus::factory()->create(['code' => 'EAD']))->create();
    expect($rules->codeLockReason($term))->toBe('El código ya no se puede cambiar: el periodo se abrió en C1, C2 y EAD.');
});
