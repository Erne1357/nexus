<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\Actor;
use Spatie\Activitylog\Models\Activity;

/**
 * Agrega `acting_as` (el puesto con que actuaba la persona) a cada entrada de bitácora que ella causa (spec §8.6).
 * Solo cuando quien causa la entrada es la persona en sesión y tiene perfil activo: un job, la consola o una Action
 * llamada para otro usuario no tienen perfil que registrar. La llave va al final de `properties` y solo se agrega
 * al crear la entrada (HC-18: la bitácora no se edita).
 *
 * Se engancha a `Activity::creating` y no a `LogActivityAction::beforeLogging()`, porque este guarda sus callbacks
 * en un arreglo estático que crece en cada arranque de Octane. `Actor` se resuelve en cada llamada, sin
 * estado propio, para que nada de una petición pase a la siguiente.
 */
final class StampActingAs
{
    public function handle(Activity $activity): void
    {
        $context = app(Actor::class);
        $user = $context->user();

        if ($user === null || $activity->causer_id === null) {
            return;
        }

        if ($activity->causer_type !== $user->getMorphClass() || (string) $activity->causer_id !== (string) $user->getKey()) {
            return;
        }

        $role = $context->activeRole();

        if ($role === null) {
            return;
        }

        $properties = $activity->properties ?? collect();

        if ($properties->has('acting_as')) {
            return;
        }

        $activity->properties = $properties->put('acting_as', $role->value);
    }
}
