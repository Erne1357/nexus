<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Students\Enums\StudentsPermission;

/*
| AZ-S17 (MS7, supuesto por confirmar): los datos de un alumno son de su campus y de su modalidad; todo permiso de
| Students que no es personal declara ambas. La excepción es atender las solicitudes de credencial (CO-01): es de una
| unidad, Comunicación y Difusión, y sirve a los alumnos de todo el Tec.
*/

it('AZ-S17: todo permiso de Students que no es personal declara Dimension::Modality y Dimension::Campus', function () {
    $without = [];

    foreach (StudentsPermission::cases() as $permission) {
        if ($permission->isPersonal() || $permission === StudentsPermission::CredentialRequestManage) {
            continue;
        }

        if (! in_array(Dimension::Modality, $permission->dimensions(), true) || ! in_array(Dimension::Campus, $permission->dimensions(), true)) {
            $without[] = $permission->value;
        }
    }

    expect($without)->toBe([]);
});

it('AZ-S17: «Mis datos» sigue siendo personal y sin dimensiones', function () {
    expect(StudentsPermission::MyRecordView->isPersonal())->toBeTrue()
        ->and(StudentsPermission::MyRecordView->dimensions())->toBe([]);
});
