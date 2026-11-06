<?php

use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\ModuleServiceProvider;

test('la matriz puesto→permisos es la que está en tests/Fixtures/Authorization/matrix.json', function () {
    $registry = app(PermissionRegistry::class);

    expect(['grants' => $registry->matrix(), 'admin_denies' => $registry->adminDenials(), 'applies_to' => $registry->applicability()])
        ->toEqual(json_decode(file_get_contents(base_path('tests/Fixtures/Authorization/matrix.json')), true));
});

test('AZ-S16: todo permiso concedido al jefe de departamento declara a qué unidades aplica', function () {
    $registry = app(PermissionRegistry::class);

    $open = collect($registry->permissions())
        ->filter(fn ($permission): bool => $registry->grants(Role::JefeDepartamento, $permission) && $permission->appliesTo() === null)
        ->map(fn ($permission): string => $permission->value)
        ->values()
        ->all();

    expect($open)->toBe([]);
});

test('todo permiso está concedido a algún puesto o lo tiene el admin', function () {
    $registry = app(PermissionRegistry::class);

    $orphans = collect($registry->permissions())
        ->filter(fn ($permission): bool => $registry->rolesGranting($permission) === [] && ! $registry->grants(Role::Admin, $permission))
        ->map(fn ($permission): string => $permission->value)
        ->values()
        ->all();

    expect($orphans)->toBe([]);
});

test('cada módulo con Policies declara sus permisos', function () {
    $silent = collect(app()->getProviders(ModuleServiceProvider::class))
        ->filter(function (ModuleServiceProvider $provider): bool {
            $dir = dirname((new ReflectionClass($provider))->getFileName());

            return is_dir("{$dir}/Policies") && $provider->permissions() === [];
        })
        ->map(fn (ModuleServiceProvider $provider): string => $provider::class)
        ->values()
        ->all();

    expect($silent)->toBe([]);
});
