<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Staffing\Enums\StaffingPermission;
use Tests\Support\Authorization\CampusBoundWrites;

/*
| AZ-S17 (MS6, supuesto por confirmar): se lee a nivel de modalidad y se escribe con campus. Los permisos de
| Staffing declaran la modalidad; los que escriben algo con campus (la asignación docente de un grupo, pedir un
| cambio sobre un grupo, aplicar el horario o el salón aprobados) declaran también el campus.
*/

/**
 * Los permisos de escritura de Staffing sobre algo con campus. Lista explícita en CampusBoundWrites: un caso nuevo que
 * escriba algo con campus se agrega allí.
 *
 * @return list<StaffingPermission>
 */
function staffingCampusBoundWrites(): array
{
    return CampusBoundWrites::staffing();
}

it('AZ-S17: los permisos de Staffing que escriben algo con campus declaran Dimension::Campus', function (StaffingPermission $permission) {
    expect($permission->dimensions())->toContain(Dimension::Campus);
})->with(fn (): array => staffingCampusBoundWrites());

it('AZ-S17: todo permiso de Staffing declara Dimension::Modality, salvo uno personal o sin dimensiones', function () {
    $without = [];

    foreach (StaffingPermission::cases() as $permission) {
        if ($permission->isPersonal() || $permission->dimensions() === []) {
            continue;
        }

        if (! in_array(Dimension::Modality, $permission->dimensions(), true)) {
            $without[] = $permission->value;
        }
    }

    expect($without)->toBe([]);
});

it('AZ-S17: lo que se lee en Staffing no declara el campus', function () {
    $reads = array_filter(
        StaffingPermission::cases(),
        fn (StaffingPermission $permission): bool => ! in_array($permission, staffingCampusBoundWrites(), true),
    );

    foreach ($reads as $permission) {
        expect($permission->dimensions())->not->toContain(Dimension::Campus);
    }
});
