<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use Tests\Fixtures\Authorization\DuplicateKeyPermission;
use Tests\Fixtures\Authorization\FakePermission;
use Tests\Fixtures\Authorization\MalformedKeyPermission;

test('concede a cada puesto lo que los módulos declaran y nada más', function () {
    $registry = PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [
            Role::Coordinador->value => [FakePermission::ViewCampus, FakePermission::ViewUnit],
            Role::Docente->value => [FakePermission::ViewUnit],
        ],
        [],
    );

    expect($registry->grants(Role::Coordinador, FakePermission::ViewCampus))->toBeTrue()
        ->and($registry->grants(Role::Docente, FakePermission::ViewCampus))->toBeFalse()
        ->and($registry->grants(Role::Subdireccion, FakePermission::ViewUnit))->toBeFalse()
        ->and($registry->rolesGranting(FakePermission::ViewUnit))->toBe([Role::Coordinador, Role::Docente])
        ->and($registry->rolesGranting(FakePermission::ViewAny))->toBe([])
        ->and($registry->permissions())->toHaveCount(count(FakePermission::cases()))
        ->and($registry->matrix())->toBe([
            'coordinador' => ['fake.thing.view_campus', 'fake.thing.view_unit'],
            'docente' => ['fake.thing.view_unit'],
        ]);
});

test('el admin tiene todo menos sus excepciones', function () {
    $registry = PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [Role::Docente->value => [FakePermission::ViewOwn]],
        [FakePermission::ViewCareer],
    );

    expect($registry->grants(Role::Admin, FakePermission::ViewCampus))->toBeTrue()
        ->and($registry->grants(Role::Admin, FakePermission::ViewCareer))->toBeFalse()
        ->and($registry->rolesGranting(FakePermission::ViewOwn))->toBe([Role::Docente])
        ->and($registry->adminDenials())->toBe(['fake.thing.view_career']);
});

test('rechaza una clave repetida entre módulos', function () {
    PermissionRegistry::fromDeclarations([FakePermission::class, DuplicateKeyPermission::class], [], []);
})->throws(LogicException::class, 'fake.thing.view_campus');

test('rechaza conceder un permiso que ningún módulo declaró', function () {
    PermissionRegistry::fromDeclarations(
        [DuplicateKeyPermission::class],
        [Role::Docente->value => [FakePermission::ViewOwn]],
        [],
    );
})->throws(LogicException::class, 'fake.thing.view_own');

test('rechaza una clave que no tiene la forma modulo.recurso.accion', function () {
    PermissionRegistry::fromDeclarations([MalformedKeyPermission::class], [], []);
})->throws(LogicException::class, 'viewthing');

test('Target guarda los valores por dimensión y el dueño', function () {
    $target = Target::of(campus: 'c1', unit: ['u1', 'u2']);

    expect($target->values(Dimension::Campus))->toBe(['c1'])
        ->and($target->values(Dimension::Unit))->toBe(['u1', 'u2'])
        ->and($target->values(Dimension::Career))->toBeNull()
        ->and(Target::ownedBy('user-1')->ownerId)->toBe('user-1')
        ->and(Dimension::Unit->column())->toBe('org_unit_id');
});

test('rechaza un permiso de un enum no declarado aunque repita una clave declarada', function () {
    expect(fn () => PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [Role::Docente->value => [DuplicateKeyPermission::Clash]],
        [],
    ))->toThrow(LogicException::class, 'fake.thing.view_campus')
        ->and(fn () => PermissionRegistry::fromDeclarations([FakePermission::class], [], [DuplicateKeyPermission::Clash]))
        ->toThrow(LogicException::class);

    $registry = PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [Role::Docente->value => [FakePermission::ViewCampus]],
        [],
    );

    expect(fn () => $registry->grants(Role::Docente, DuplicateKeyPermission::Clash))->toThrow(LogicException::class)
        ->and(fn () => $registry->rolesGranting(DuplicateKeyPermission::Clash))->toThrow(LogicException::class);
});

test('rechaza conceder permisos a un puesto que no existe', function () {
    PermissionRegistry::fromDeclarations([FakePermission::class], ['rector' => [FakePermission::ViewAny]], []);
})->throws(LogicException::class, 'rector');

test('rechaza una excepción del admin que ningún módulo declaró', function () {
    PermissionRegistry::fromDeclarations([DuplicateKeyPermission::class], [], [FakePermission::ViewAny]);
})->throws(LogicException::class, 'fake.thing.view_any');

test('Target trata la lista vacía como sin valor y rechaza cadenas vacías', function () {
    expect(Target::of(unit: [])->values(Dimension::Unit))->toBeNull()
        ->and(fn () => Target::of(unit: ''))->toThrow(LogicException::class)
        ->and(fn () => Target::of(campus: ['']))->toThrow(LogicException::class);
});
