<?php

use App\Modules\AcademicLoad\Enums\ProposalTileState;

/*
| El estado de un mosaico de la retícula de la propuesta de carrera (DESIGN.md, "Retícula de la propuesta de
| carrera", maqueta C): lo que decidió el departamento de la materia manda sobre sus grupos, y una materia sin
| grupos se marca aunque su departamento no haya decidido. Cada estado tiene su relleno, su icono y su nombre en la
| leyenda, porque el color nunca va solo.
*/

it('regresada y aceptada mandan sobre los grupos; sin grupos sobre «con grupos»', function (string $part, int $groups, ProposalTileState $state) {
    expect(ProposalTileState::of($part, $groups))->toBe($state);
})->with([
    ['returned', 0, ProposalTileState::Returned],
    ['returned', 3, ProposalTileState::Returned],
    ['accepted', 0, ProposalTileState::Accepted],
    ['accepted', 2, ProposalTileState::Accepted],
    ['pending', 0, ProposalTileState::NoGroups],
    ['pending', 1, ProposalTileState::WithGroups],
]);

it('cada estado tiene su relleno, su icono y su nombre en la leyenda, en el orden de la leyenda', function () {
    expect(array_map(fn (ProposalTileState $state): array => [$state->value, $state->tileClasses(), $state->icon(), $state->label()], ProposalTileState::cases()))->toBe([
        ['pending', 'border-base-content/30 bg-base-100 text-base-content', null, 'Con grupos'],
        ['none', 'border-dashed border-warning bg-warning/10 text-warning', 'o-minus-circle', 'Sin grupos'],
        ['accepted', 'border-success/40 bg-success/10 text-success', 'o-lock-closed', 'Aceptada por el departamento'],
        ['returned', 'border-error/40 bg-error/10 text-error', 'o-arrow-uturn-left', 'Regresada por el departamento'],
    ]);
});
