<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use Tests\Fixtures\Authorization\FakePermission;

enum DuplicateKeyPermission: string implements Permission
{
    case Clash = 'fake.thing.view_campus';

    public function label(): string
    {
        return $this->value;
    }

    public function dimensions(): array
    {
        return [];
    }

    public function isPersonal(): bool
    {
        return false;
    }
}

enum MalformedKeyPermission: string implements Permission
{
    case Bad = 'viewthing';

    public function label(): string
    {
        return $this->value;
    }

    public function dimensions(): array
    {
        return [];
    }

    public function isPersonal(): bool
    {
        return false;
    }
}

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
