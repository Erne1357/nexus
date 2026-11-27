<?php

use App\Modules\AcademicLoad\Enums\SuggestionState;
use App\Modules\AcademicLoad\Rules\ProposalSuggestionState;
use App\Modules\Demand\Rules\DemandSettings;

/*
| ProposalSuggestionState (tarea 5.5, confirmado el 16 de noviembre de 2026): el estado de una fila de la propuesta frente a
| la proyección vigente. Sin proyección, sin tocar, corregida sin cambio pendiente o corregida con la proyección cambiada.
| El aviso compara lo sugerido nuevo contra lo sugerido guardado, nunca contra lo capturado; la diferencia grande solo
| resalta, con el umbral de DemandSettings (20 % de omisión).
*/

function suggestionStateOf(int $expected, int $groups, ?int $suggestedExpected, ?int $suggestedGroups, bool $captured, ?int $forecastExpected, ?int $forecastGroups): ProposalSuggestionState
{
    return ProposalSuggestionState::of($expected, $groups, $suggestedExpected, $suggestedGroups, $captured ? now() : null, $forecastExpected, $forecastGroups, new DemandSettings);
}

it('sin proyección ni sugerido guardado es none y no resalta nada', function () {
    $state = suggestionStateOf(30, 1, null, null, true, null, null);

    expect($state->state)->toBe(SuggestionState::None)
        ->and($state->largeDifferenceExpected)->toBeFalse()
        ->and($state->newExpected)->toBeNull();
});

it('una fila sin tocar es suggested, aunque la proyección vigente difiera', function () {
    $state = suggestionStateOf(53, 2, 53, 2, false, 60, 2);

    expect($state->state)->toBe(SuggestionState::Suggested)
        ->and($state->newExpected)->toBeNull();
});

it('una fila corregida con la proyección que vio es captured', function () {
    $state = suggestionStateOf(40, 2, 53, 2, true, 53, 2);

    expect($state->state)->toBe(SuggestionState::Captured)
        ->and($state->newExpected)->toBeNull()
        ->and($state->newGroups)->toBeNull();
});

it('una fila corregida con la proyección cambiada es changed y trae lo nuevo, comparado contra lo guardado y no contra lo capturado', function () {
    $state = suggestionStateOf(40, 2, 53, 2, true, 70, 2);

    expect($state->state)->toBe(SuggestionState::Changed)
        ->and($state->newExpected)->toBe(70)
        ->and($state->newGroups)->toBe(2);

    // Lo capturado coincide con lo nuevo, pero lo guardado no: igual hay aviso.
    expect(suggestionStateOf(70, 2, 53, 2, true, 70, 2)->state)->toBe(SuggestionState::Changed);
});

it('una proyección que aparece después, en una fila corregida que nació sin ella, es changed', function () {
    expect(suggestionStateOf(30, 1, null, null, true, 45, 2)->state)->toBe(SuggestionState::Changed);
});

it('una proyección que desapareció deja la guardada como vigente', function () {
    expect(suggestionStateOf(40, 2, 53, 2, true, null, null)->state)->toBe(SuggestionState::Captured);
});

it('resalta la diferencia grande en los dos sentidos y por separado en esperados y grupos', function () {
    // 20 % de 50 son 10: 60 y 40 resaltan, 59 y 41 no.
    expect(suggestionStateOf(60, 2, 50, 2, true, 50, 2)->largeDifferenceExpected)->toBeTrue()
        ->and(suggestionStateOf(40, 2, 50, 2, true, 50, 2)->largeDifferenceExpected)->toBeTrue()
        ->and(suggestionStateOf(59, 2, 50, 2, true, 50, 2)->largeDifferenceExpected)->toBeFalse()
        ->and(suggestionStateOf(41, 2, 50, 2, true, 50, 2)->largeDifferenceExpected)->toBeFalse();

    $groups = suggestionStateOf(50, 4, 50, 2, true, 50, 2);

    expect($groups->largeDifferenceGroups)->toBeTrue()
        ->and($groups->largeDifferenceExpected)->toBeFalse();
});

it('con sugerido cero resalta cualquier captura mayor que cero y ninguna si se captura cero', function () {
    expect(suggestionStateOf(5, 1, 0, 0, true, 0, 0)->largeDifferenceExpected)->toBeTrue()
        ->and(suggestionStateOf(0, 0, 0, 0, true, 0, 0)->largeDifferenceExpected)->toBeFalse();
});

it('la diferencia grande se mide contra lo sugerido vigente y solo en filas corregidas', function () {
    $changed = suggestionStateOf(50, 2, 50, 2, true, 100, 3);

    expect($changed->state)->toBe(SuggestionState::Changed)
        ->and($changed->largeDifferenceExpected)->toBeTrue()
        ->and(suggestionStateOf(10, 1, 50, 2, false, 50, 2)->largeDifferenceExpected)->toBeFalse();
});

it('con paquetes, la diferencia grande de grupos se mide contra los grupos sugeridos efectivos de la fila (paquetes más sueltos)', function () {
    $atEffective = ProposalSuggestionState::of(90, 4, 90, 3, now(), 90, 3, new DemandSettings, effectiveGroups: 4);
    $threeMore = ProposalSuggestionState::of(90, 7, 90, 3, now(), 90, 3, new DemandSettings, effectiveGroups: 4);

    expect($atEffective->largeDifferenceGroups)->toBeFalse()
        ->and($atEffective->effectiveGroups)->toBe(4)
        ->and($threeMore->largeDifferenceGroups)->toBeTrue()
        ->and($threeMore->largeDifferenceExpected)->toBeFalse();
});

it('«Cambió» sigue comparando lo sugerido guardado con la proyección cruda, con o sin paquetes', function () {
    $state = ProposalSuggestionState::of(90, 4, 90, 3, now(), 100, 3, new DemandSettings, effectiveGroups: 4);

    expect($state->state)->toBe(SuggestionState::Changed)
        ->and($state->newExpected)->toBe(100)
        ->and($state->newGroups)->toBe(3);
});
