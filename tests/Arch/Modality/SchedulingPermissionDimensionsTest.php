<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Scheduling\Enums\SchedulingPermission;

/*
| AZ-S17 (MS6, supuesto por confirmar): los horarios y el tablero se leen a nivel de modalidad y se escribe con campus.
| Los permisos de Scheduling declaran la modalidad; los que crean, mueven o cancelan algo de un campus declaran también
| el campus.
*/

/**
 * Los permisos de escritura de Scheduling sobre algo con campus: crear o cancelar un grupo, acomodar sus sesiones,
 * asignar salón y repartir cupos. Lista explícita: un caso nuevo que escriba algo con campus se agrega aquí.
 *
 * @return list<SchedulingPermission>
 */
function schedulingCampusBoundWrites(): array
{
    return [
        SchedulingPermission::GroupManage,
        SchedulingPermission::SessionEdit,
        SchedulingPermission::RoomAssign,
        SchedulingPermission::QuotaManage,
    ];
}

it('AZ-S17: los permisos de Scheduling que escriben algo con campus declaran Dimension::Campus', function (SchedulingPermission $permission) {
    expect($permission->dimensions())->toContain(Dimension::Campus);
})->with(fn (): array => schedulingCampusBoundWrites());

it('AZ-S17: todo permiso de Scheduling declara Dimension::Modality, salvo uno personal o sin dimensiones', function () {
    $without = [];

    foreach (SchedulingPermission::cases() as $permission) {
        if ($permission->isPersonal() || $permission->dimensions() === []) {
            continue;
        }

        if (! in_array(Dimension::Modality, $permission->dimensions(), true)) {
            $without[] = $permission->value;
        }
    }

    expect($without)->toBe([]);
});

it('AZ-S17: lo que se lee en Scheduling no declara el campus', function () {
    $reads = array_filter(
        SchedulingPermission::cases(),
        fn (SchedulingPermission $permission): bool => ! in_array($permission, schedulingCampusBoundWrites(), true),
    );

    foreach ($reads as $permission) {
        expect($permission->dimensions())->not->toContain(Dimension::Campus);
    }
});
