<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\ModalityProfile;

/*
| El diagrama de "Máquina de estados de la carga académica" (docs/proceso/proceso-propuesto.md) como datos:
| cada par permitido y todos los demás, la matriz completa de 8 x 8, para un campus sin visto bueno de
| Subdirección (cerrada publica directo) y para uno con él (cerrada pasa a aprobada). Cerrada, aprobada y
| publicada se reabren a ajustes (confirmado el 10 de noviembre de 2026). El re-análisis (tarea 8.1) sale de publicada,
| se cierra y sigue por el mismo camino: no publica directo (RA-03), y cerrada y aprobada también pueden devolverse a
| re-análisis (RA-08). La flecha de re-análisis a publicada es la de cancelar sin cambios (RA-10): solo la toma CancelReanalysis.
*/

const COMMON_LOAD_TRANSITIONS = [
    'draft' => ['proposed'],
    'proposed' => ['staffing'],
    'staffing' => ['adjusting', 'closed'],
    'adjusting' => ['staffing'],
    'approved' => ['published', 'adjusting', 'reanalysis'],
    'published' => ['reanalysis', 'adjusting'],
    'reanalysis' => ['closed', 'published'],
];

function allowedLoadTransitions(bool $requiresApproval): array
{
    return [...COMMON_LOAD_TRANSITIONS, 'closed' => [$requiresApproval ? 'approved' : 'published', 'adjusting', 'reanalysis']];
}

function loadProfile(bool $requiresApproval): ModalityProfile
{
    return ModalityProfile::fromArray([...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => $requiresApproval]);
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
