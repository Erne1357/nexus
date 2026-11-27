<?php

use App\Modules\Core\Events\Concerns\CanBeVetoed;

/*
| CanBeVetoed: base de los eventos con veto (docs/arquitectura/arquitectura.md, "Eventos con veto").
| Un listener de un módulo de la derecha del mapa bloquea la operación con un motivo; la Action
| que despachó el evento lee blocked() y reasons().
*/

function vetoableEvent(): object
{
    return new class
    {
        use CanBeVetoed;
    };
}

it('sin bloqueos no está bloqueado y no tiene motivos', function () {
    $event = vetoableEvent();

    expect($event->blocked())->toBeFalse()
        ->and($event->reasons())->toBe([]);
});

it('con un bloqueo queda bloqueado con su motivo', function () {
    $event = vetoableEvent();
    $event->block('La necesitan 3 alumnos.');

    expect($event->blocked())->toBeTrue()
        ->and($event->reasons())->toBe(['La necesitan 3 alumnos.']);
});

it('junta los motivos de varios bloqueos en el orden en que llegan', function () {
    $event = vetoableEvent();
    $event->block('primero');
    $event->block('segundo');

    expect($event->reasons())->toBe(['primero', 'segundo']);
});
