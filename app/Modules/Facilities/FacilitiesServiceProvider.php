<?php

namespace App\Modules\Facilities;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Listeners\BlockDepartmentDeletionWithRooms;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use Illuminate\Support\Facades\Event;

/**
 * Módulo Facilities: edificios, salones, bloqueos de salón y salones por defecto de las materias
 * (docs/dominio/modelo-de-datos.md, "Facilities"). Depende de Core y Academic.
 *
 * Escucha a Academic, que no depende de Facilities: veta borrar un departamento que es dueño de salones.
 */
final class FacilitiesServiceProvider extends ModuleServiceProvider
{
    public function navigation(): array
    {
        return [
            new NavigationItem('Edificios', 'facilities.buildings', 'o-building-office', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Building::class]),
            new NavigationItem('Salones', 'facilities.rooms', 'o-rectangle-stack', NavigationArea::Academico,
                ability: 'viewAny', arguments: [Room::class]),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithRooms::class);
    }
}
