<?php

use App\Modules\Core\Authorization\PermissionRegistry;

test('la matriz puesto→permisos es la que está en tests/Fixtures/Authorization/matrix.json', function () {
    $registry = app(PermissionRegistry::class);

    expect(['grants' => $registry->matrix(), 'admin_denies' => $registry->adminDenials()])
        ->toEqual(json_decode(file_get_contents(base_path('tests/Fixtures/Authorization/matrix.json')), true));
});
