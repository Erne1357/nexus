<?php

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Dimension;

/*
| AZ-S17 (MS6, supuesto por confirmar): se lee a nivel de modalidad y se escribe con campus. Los permisos de
| AcademicLoad declaran la modalidad; los que escriben algo con campus declaran también el campus.
*/

/**
 * Los permisos de escritura de AcademicLoad sobre algo con campus: una fila de la propuesta (de un campus, M7) y el
 * acuerdo de un préstamo de salón o de un grupo compartido (ambos en un campus). Lista explícita: un caso nuevo que
 * escriba algo con campus se agrega aquí.
 *
 * @return list<AcademicLoadPermission>
 */
function academicLoadCampusBoundWrites(): array
{
    return [
        AcademicLoadPermission::ProposalUpdate,
        AcademicLoadPermission::AgreementRequest,
    ];
}

it('AZ-S17: los permisos de AcademicLoad que escriben algo con campus declaran Dimension::Campus', function (AcademicLoadPermission $permission) {
    expect($permission->dimensions())->toContain(Dimension::Campus);
})->with(fn (): array => academicLoadCampusBoundWrites());

it('AZ-S17: todo permiso de AcademicLoad declara Dimension::Modality, salvo uno personal o sin dimensiones', function () {
    $without = [];

    foreach (AcademicLoadPermission::cases() as $permission) {
        if ($permission->isPersonal() || $permission->dimensions() === []) {
            continue;
        }

        if (! in_array(Dimension::Modality, $permission->dimensions(), true)) {
            $without[] = $permission->value;
        }
    }

    expect($without)->toBe([]);
});

it('AZ-S17: lo que se lee en AcademicLoad no declara el campus', function () {
    $reads = array_filter(
        AcademicLoadPermission::cases(),
        fn (AcademicLoadPermission $permission): bool => ! in_array($permission, academicLoadCampusBoundWrites(), true),
    );

    foreach ($reads as $permission) {
        expect($permission->dimensions())->not->toContain(Dimension::Campus);
    }
});
