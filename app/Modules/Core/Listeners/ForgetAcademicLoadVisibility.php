<?php

namespace App\Modules\Core\Listeners;

use App\Modules\Core\Authorization\AcademicLoadVisibility;
use App\Modules\Core\Events\SettingsUpdated;
use Illuminate\Foundation\Http\Events\RequestHandled;

/**
 * Olvida si la carga académica está oculta (MN-05), para que la siguiente pregunta lo vuelva a leer: si el interruptor
 * cambió, lo que se pinte después en la misma petición usa el valor nuevo; y al terminar cada petición, porque lo leído
 * vale para una sola (Octane ya olvida lo `scoped` al empezar otra; sin Octane, y en las pruebas, la instancia sigue).
 */
final class ForgetAcademicLoadVisibility
{
    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    public function handle(SettingsUpdated|RequestHandled $event): void
    {
        if ($event instanceof RequestHandled || in_array(AcademicLoadVisibility::SETTING, $event->keys, true)) {
            $this->visibility->forget();
        }
    }
}
