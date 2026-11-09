<?php

namespace App\Modules\Facilities;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use App\Modules\Facilities\Enums\RoomType;
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

    public function permissions(): array
    {
        return [FacilitiesPermission::class];
    }

    public function grants(): array
    {
        return [
            Role::Subdireccion->value => FacilitiesPermission::cases(),
        ];
    }

    public function boot(): void
    {
        parent::boot();

        Event::listen(DepartmentDeleting::class, BlockDepartmentDeletionWithRooms::class);
    }

    /**
     * Demo por puntos: los edificios y salones de los campus del volumen llevan a «catálogos».
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Edificios y salones', fn (DemoContext $demo) => $demo->runSeeder(FacilitiesDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'seed' => $demo->seed,
            ])),
        ];
    }

    /**
     * Con Campus I, sus prácticas grandes caben en salones de práctica de 35 lugares o más (los 6 laboratorios
     * generales): sin ellos, el horario limpio no cabe con HC-05 (medición del Riel B, 9 de noviembre de 2026).
     */
    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::staged(), 'Campus I tiene salones de práctica de 35 lugares o más', function (DemoContext $demo): true|string {
                if (! in_array('C1', $demo->campuses, true)) {
                    return true;
                }

                $practice = array_values(array_filter(RoomType::cases(), fn (RoomType $type): bool => $type->isPracticeSpace()));
                $rooms = Room::query()
                    ->whereIn('campus_id', Campus::query()->where('code', 'C1')->select('id'))
                    ->where('is_active', true)
                    ->whereIn('type', $practice)
                    ->where('capacity', '>=', 35)
                    ->count();

                return $rooms >= 6 ? true : "Solo hay {$rooms}; hacen falta 6.";
            }),
        ];
    }
}
