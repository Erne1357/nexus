<?php

namespace App\Modules\Facilities;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Facilities\Listeners\BlockDepartmentDeletionWithRooms;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Facilities: edificios, salones, bloqueos de salón y salones por defecto de las materias
 * (docs/dominio/modelo-de-datos.md, "Facilities"). Depende de Core y Academic.
 *
 * Escucha a Academic, que no depende de Facilities: veta borrar un departamento que es dueño de salones.
 */
final class FacilitiesServiceProvider extends ModuleServiceProvider
{
    public function boot(): void
    {
        parent::boot();

        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithRooms::class);
    }
}
