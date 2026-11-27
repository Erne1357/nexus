<?php

use App\Modules\Core\Models\Campus;
use App\Modules\Core\Rules\CampusRules;
use Illuminate\Validation\ValidationException;

/*
| CampusRules: las reglas del campus que comparten CreateCampus y UpdateCampus
| (docs/dominio/modelo-de-datos.md, `campuses`). Cada regla rota es una ValidationException sobre
| su campo. Es feature porque ValidationException necesita el contenedor y la unicidad, la base.
*/

function rejectionOf(Closure $rule): array
{
    try {
        $rule();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    throw new RuntimeException('La regla no rechazó el valor.');
}

it('deja el código en mayúsculas y sin espacios, también NBSP y otros espacios de Unicode', function () {
    expect(app(CampusRules::class)->normalizeCode(" c\u{00A0}3\t\u{2003}"))->toBe('C3');
});

it('rechaza un código que queda vacío al normalizar, como uno hecho solo de NBSP', function () {
    expect(rejectionOf(fn () => app(CampusRules::class)->normalizeCode("\u{00A0}\u{00A0}")))
        ->toBe(['code' => ['El código no puede quedar vacío ni ser solo espacios.']]);
});

it('rechaza un código que no es UTF-8 válido', function () {
    expect(rejectionOf(fn () => app(CampusRules::class)->normalizeCode("C\xC3\x28")))
        ->toBe(['code' => ['El código no es texto válido.']]);
});

it('rechaza un código ya usado, también por un campus borrado, y acepta el propio', function () {
    $own = Campus::factory()->create(['code' => 'C2']);
    Campus::factory()->create(['code' => 'C9'])->delete();
    $rules = app(CampusRules::class);

    expect(rejectionOf(fn () => $rules->ensureCodeIsFree('C2')))
        ->toBe(['code' => ['Ya existe un campus con el código C2, aunque esté desactivado o borrado.']])
        ->and(rejectionOf(fn () => $rules->ensureCodeIsFree('C9', $own)))->toHaveKey('code');

    $rules->ensureCodeIsFree('C2', $own);
});

it('MS3: no valida un perfil de flujo: el campus no lo tiene', function () {
    expect(method_exists(CampusRules::class, 'profile'))->toBeFalse();
});
