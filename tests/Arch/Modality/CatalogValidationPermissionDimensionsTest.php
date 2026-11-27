<?php

use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\Core\Authorization\Dimension;

/*
| AZ-S17 (supuesto por confirmar): las revisiones de catálogos son del departamento en todo el Tec, sin campus ni
| modalidad (los catálogos son de la institución).
*/

it('AZ-S17: ningún permiso de CatalogValidation declara Dimension::Campus ni Dimension::Modality', function () {
    $with = [];

    foreach (CatalogValidationPermission::cases() as $permission) {
        foreach ([Dimension::Campus, Dimension::Modality] as $dimension) {
            if (in_array($dimension, $permission->dimensions(), true)) {
                $with[] = $permission->value.':'.$dimension->name;
            }
        }
    }

    expect($with)->toBe([]);
});
