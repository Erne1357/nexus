<?php

namespace App\Modules\AcademicLoad\Listeners;

use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\Core\Authorization\AcademicLoadVisibility;

/**
 * Una carga cambió de estado, así que cambiaron sus etapas (MN-06): Core olvida las que leyó en la petición, para que lo
 * que se pinte después en ella (el menú, la campana) muestre las pantallas de la etapa nueva.
 */
final class ForgetLoadStagesOnTransition
{
    public function __construct(private readonly AcademicLoadVisibility $visibility) {}

    public function handle(LoadTransitioned $event): void
    {
        $this->visibility->forget();
    }
}
