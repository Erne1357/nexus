<?php

namespace App\Modules\Facilities;

use App\Modules\Academic\Events\DepartmentDeleting;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoStep;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Enums\NavigationArea;
use App\Modules\Core\Enums\NavigationGroup;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\ModuleServiceProvider;
use App\Modules\Core\Navigation\NavigationItem;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Facilities\Enums\FacilitiesPermission;
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
            new NavigationItem('Edificios', 'facilities.buildings', 'o-building-office', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
                ability: 'viewAny', arguments: [Building::class]),
            new NavigationItem('Salones', 'facilities.rooms', 'o-rectangle-stack', NavigationArea::Administracion, group: NavigationGroup::Catalogos,
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
     * Demo por puntos: los edificios y salones de los campus del volumen llevan a «catálogos». Con el volumen masivo,
     * además, los edificios y salones grandes que pide su número de grupos (FacilitiesDemoSeeder::PROCESS_BUILDINGS).
     */
    public function demoSteps(): array
    {
        return [
            new DemoStep(DemoCheckpoint::Catalogs, 'Edificios y salones', fn (DemoContext $demo) => $demo->runSeeder(FacilitiesDemoSeeder::class, [
                'campuses' => $demo->campuses,
                'seed' => $demo->seed,
                'withProcessRooms' => $demo->volume === DemoVolume::Massive,
            ])),
        ];
    }

    /**
     * Con el volumen masivo, cada campus tiene los salones grandes sin dueño que pide su número de grupos
     * (FacilitiesDemoSeeder::PROCESS_BUILDINGS): sin ellos, el horario limpio y el paso de salones no caben con HC-05 y
     * HC-06 (mediciones de los rieles A y B, 9 de noviembre de 2026). La misma condición que el paso.
     */
    public function demoChecks(): array
    {
        return [
            new DemoCheck(DemoCheckpoint::staged(), 'Con el masivo, cada campus tiene sus salones grandes de la demo por puntos', function (DemoContext $demo): true|string {
                if ($demo->volume !== DemoVolume::Massive) {
                    return true;
                }

                $missing = [];

                foreach ($demo->campuses as $code) {
                    $expected = FacilitiesDemoSeeder::processRoomCodes($code);
                    $found = Room::query()
                        ->whereIn('campus_id', Campus::query()->where('code', $code)->select('id'))
                        ->whereIn('code', $expected)
                        ->where('is_active', true)
                        ->whereNull('department_id')
                        ->count();

                    if ($found !== count($expected)) {
                        $missing[] = sprintf('%s tiene %d de %d', $code, $found, count($expected));
                    }
                }

                return $missing === [] ? true : implode('; ', $missing).'.';
            }),
        ];
    }
}
