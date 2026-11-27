<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Events\SettingsUpdated;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Olvida si la carga académica está oculta (MN-05), si los jefes y JPD ven su etapa y las etapas leídas (MN-06), para que
 * la siguiente pregunta lo vuelva a leer: si uno de los dos parámetros cambió, lo que se pinte después en la misma petición
 * usa el valor nuevo; y al terminar cada petición, porque lo leído vale para una sola (Octane ya olvida lo `scoped` al
 * empezar otra; sin Octane, y en las pruebas, la instancia sigue).
 */
final class ForgetAcademicLoadVisibility
{
    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    public function handle(SettingsUpdated|RequestHandled $event): void
    {
        if ($event instanceof RequestHandled || array_intersect([AcademicLoadVisibility::SETTING, AcademicLoadVisibility::HEADS_SETTING], $event->keys) !== []) {
            $this->visibility->forget();
        }
    }
}
