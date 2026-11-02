<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\CampusProfile;

/*
| El diagrama de "Máquina de estados de la carga académica" (docs/proceso/proceso-propuesto.md) como datos:
| cada par permitido y todos los demás, la matriz completa de 8 x 8, para un campus sin visto bueno de
| Subdirección (cerrada publica directo) y para uno con él (cerrada pasa a aprobada).
*/

const COMMON_LOAD_TRANSITIONS = [
    'draft' => ['proposed'],
    'proposed' => ['staffing'],
    'staffing' => ['adjusting', 'closed'],
    'adjusting' => ['staffing'],
    'approved' => ['published', 'adjusting'],
    'published' => ['reanalysis'],
    'reanalysis' => ['published'],
];

function allowedLoadTransitions(bool $requiresApproval): array
{
    return [...COMMON_LOAD_TRANSITIONS, 'closed' => [$requiresApproval ? 'approved' : 'published']];
}

function loadProfile(bool $requiresApproval): CampusProfile
{
    return CampusProfile::fromArray([...CampusProfile::DEFAULTS, 'requires_subdireccion_approval' => $requiresApproval]);
}

dataset('load transitions matrix', function () {
    foreach ([false => 'sin visto bueno', true => 'con visto bueno'] as $requiresApproval => $label) {
        foreach (LoadStatus::cases() as $from) {
            foreach (LoadStatus::cases() as $to) {
                yield "{$label}: {$from->value} a {$to->value}" => [$from, $to, (bool) $requiresApproval, in_array($to->value, allowedLoadTransitions((bool) $requiresApproval)[$from->value], true)];
            }
        }
    }
});

it('permite exactamente las transiciones del diagrama y ninguna otra', function (LoadStatus $from, LoadStatus $to, bool $requiresApproval, bool $allowed) {
    expect($from->canTransitionTo($to, loadProfile($requiresApproval)))->toBe($allowed);
})->with('load transitions matrix');

it('lista los destinos de cada estado en transitions()', function (bool $requiresApproval) {
    foreach (LoadStatus::cases() as $from) {
        expect(array_map(fn (LoadStatus $to): string => $to->value, $from->transitions(loadProfile($requiresApproval))))
            ->toBe(allowedLoadTransitions($requiresApproval)[$from->value]);
    }
})->with([false, true]);
