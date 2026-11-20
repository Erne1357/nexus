<?php

use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\PermissionGrantRules;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\Role;
use Tests\Fixtures\Authorization\FakePermission;

/*
| Qué permiso se le puede dar a qué puesto en el panel: CF-S1 (el admin no se edita), CF-03 (candados) y CF-04 (nada
| que amplíe el alcance; lo de fábrica siempre vale). Con el registro de prueba: el jefe de departamento tiene de
| fábrica ViewCareer, que sin la excepción se bloquearía; ViewUnit es candado.
*/

function fakeGrantRules(): PermissionGrantRules
{
    return new PermissionGrantRules(PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [Role::JefeDepartamento->value => [FakePermission::ViewCareer], Role::Docente->value => [FakePermission::ViewOwn]],
        [],
        [FakePermission::ViewUnit],
    ));
}

it('CF-04: cada puesto acota las dimensiones de su nombramiento y las de su regla general', function () {
    $bounded = collect(Role::cases())->mapWithKeys(fn (Role $role): array => [
        $role->value => array_map(fn (Dimension $dimension): string => $dimension->value, $role->boundedDimensions()),
    ])->all();

    expect($bounded)->toBe([
        'admin' => ['campus', 'unit', 'career', 'modality'],
        'subdireccion' => ['campus', 'unit', 'modality'],
        'directivo' => ['campus', 'unit', 'career', 'modality'],
        'jefe_departamento' => ['campus', 'unit', 'modality'],
        'jefe_proyecto_docente' => ['campus', 'unit', 'modality'],
        'coordinador' => ['campus', 'career', 'modality'],
        'docente' => [],
        'alumno' => [],
    ]);
});

it('CF-S1: el admin no se edita', function () {
    $rules = fakeGrantRules();

    expect($rules->positionProblem(Role::Admin))->toBe('El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.')
        ->and($rules->grantProblem(Role::Admin, FakePermission::ViewCampus))->toBe($rules->positionProblem(Role::Admin))
        ->and($rules->positionProblem(Role::Coordinador))->toBeNull();
});

it('CF-03: un candado no se da a ningún puesto ni va en un rol', function () {
    $rules = fakeGrantRules();

    expect($rules->lockProblem(FakePermission::ViewUnit))->toBe('«fake.thing.view_unit» es solo del admin: no se da a otro puesto.')
        ->and($rules->grantProblem(Role::Subdireccion, FakePermission::ViewUnit))->toBe($rules->lockProblem(FakePermission::ViewUnit))
        ->and($rules->lockProblem(FakePermission::ViewCampus))->toBeNull();
});

it('CF-04: el docente y el alumno solo reciben permisos personales', function (Role $role) {
    $rules = fakeGrantRules();

    expect($rules->grantProblem($role, FakePermission::ViewCampus))->toBe("«fake.thing.view_campus» no se da a {$role->listLabel()}: el puesto no lleva alcance y solo recibe permisos personales.")
        ->and($rules->grantProblem($role, FakePermission::ViewOwn))->toBeNull();
})->with([Role::Docente, Role::Alumno]);

it('CF-04: un permiso de gestión se bloquea si el puesto no acota su campus o su modalidad, o ni su departamento ni su carrera', function (Role $role, FakePermission $permission, ?string $problem) {
    expect(fakeGrantRules()->grantProblem($role, $permission))->toBe($problem);
})->with([
    'el coordinador no lleva departamento' => [Role::Coordinador, FakePermission::ViewCampusUnit, '«fake.thing.view_campus_unit» no se da a Coordinador de carrera: el puesto no lleva departamento y el permiso valdría en todos los departamentos.'],
    'el coordinador lleva modalidad, carrera y campus' => [Role::Coordinador, FakePermission::ViewModalityCampusCareer, null],
    'el jefe de proyecto docente no lleva carrera' => [Role::JefeProyectoDocente, FakePermission::ViewModalityCareer, '«fake.thing.view_modality_career» no se da a Jefe de proyecto docente: el puesto no lleva carrera y el permiso valdría en todas las carreras.'],
    'el jefe de proyecto docente acota el campus opcional' => [Role::JefeProyectoDocente, FakePermission::ViewCampusUnit, null],
    'la Subdirección es general en campus y modalidad' => [Role::Subdireccion, FakePermission::ViewModalityUnit, null],
    'la Subdirección no lleva carrera' => [Role::Subdireccion, FakePermission::ViewCampusCareer, '«fake.thing.view_campus_career» no se da a Subdirección Académica: el puesto no lleva carrera y el permiso valdría en todas las carreras.'],
    'un permiso sin dimensiones no amplía nada' => [Role::Coordinador, FakePermission::ViewAny, null],
    'con unidad y carrera, al coordinador le basta la carrera' => [Role::Coordinador, FakePermission::ViewCampusUnitCareer, null],
    'con unidad y carrera, al jefe le basta el departamento' => [Role::JefeDepartamento, FakePermission::ViewCampusUnitCareer, null],
    'con unidad y carrera, a la Subdirección le basta el departamento' => [Role::Subdireccion, FakePermission::ViewCampusUnitCareer, null],
    'con carrera y sin unidad, el jefe no la acota' => [Role::JefeDepartamento, FakePermission::ViewModalityCareer, '«fake.thing.view_modality_career» no se da a Jefe de departamento: el puesto no lleva carrera y el permiso valdría en todas las carreras.'],
]);

it('CF-04: lo que el código concede de fábrica siempre vale, aunque sin la excepción se bloquearía', function () {
    $rules = fakeGrantRules();

    expect($rules->grantProblem(Role::JefeDepartamento, FakePermission::ViewCareer))->toBeNull()
        ->and($rules->scopeProblem(Role::JefeDepartamento, FakePermission::ViewCareer))->toBe('«fake.thing.view_career» no se da a Jefe de departamento: el puesto no lleva carrera y el permiso valdría en todas las carreras.')
        ->and($rules->grantProblem(Role::JefeProyectoDocente, FakePermission::ViewCareer))->not->toBeNull();
});

it('marca lo que valdría en todo el Tec: de gestión, sin unidad ni carrera, y sin dimensión que el puesto fije', function (Role $role, FakePermission $permission, bool $wide) {
    expect(fakeGrantRules()->isInstitutionWide($role, $permission))->toBe($wide);
})->with([
    'sin dimensiones, para cualquiera' => [Role::Coordinador, FakePermission::ViewAny, true],
    'modalidad, para el jefe' => [Role::JefeDepartamento, FakePermission::ViewModality, true],
    'modalidad, no para el coordinador' => [Role::Coordinador, FakePermission::ViewModality, false],
    'campus, para la Subdirección' => [Role::Subdireccion, FakePermission::ViewCampus, true],
    'campus, no para el jefe de proyecto docente (lo admite)' => [Role::JefeProyectoDocente, FakePermission::ViewCampus, false],
    'con unidad, no' => [Role::JefeDepartamento, FakePermission::ViewModalityUnit, false],
    'personal, no' => [Role::Docente, FakePermission::ViewOwn, false],
    'AZ-03: lo que lee el directivo vale en todo el Tec, aunque declare unidad' => [Role::Directivo, FakePermission::ViewCampusUnit, true],
    'AZ-03: lo que lee el directivo vale en todo el Tec, aunque declare carrera' => [Role::Directivo, FakePermission::ViewCareer, true],
    'AZ-03: lo personal del directivo no' => [Role::Directivo, FakePermission::ViewOwn, false],
]);

it('CF-03: el registro rechaza conceder un candado de fábrica o hacerlo excepción del admin', function () {
    expect(fn () => PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => [FakePermission::ViewAny]], [], [FakePermission::ViewAny]))
        ->toThrow(LogicException::class, 'fake.thing.view_any')
        ->and(fn () => PermissionRegistry::fromDeclarations([FakePermission::class], [], [FakePermission::ViewAny], [FakePermission::ViewAny]))
        ->toThrow(LogicException::class, 'fake.thing.view_any');
});

it('CF-03: un candado de lectura que el código da de fábrica a quien lee en todo el Tec vale solo para ese puesto', function () {
    $registry = PermissionRegistry::fromDeclarations(
        [FakePermission::class],
        [Role::Directivo->value => [FakePermission::ViewAny, FakePermission::ViewCampus]],
        [],
        [FakePermission::ViewAny],
        [Role::Directivo->value => [FakePermission::ViewAny]],
    );
    $rules = new PermissionGrantRules($registry);

    expect($registry->isAdminOnly(FakePermission::ViewAny))->toBeTrue()
        ->and($registry->rolesGranting(FakePermission::ViewAny))->toBe([Role::Directivo])
        ->and($registry->isFactoryLockException(Role::Directivo, FakePermission::ViewAny))->toBeTrue()
        ->and($registry->isFactoryLockException(Role::Subdireccion, FakePermission::ViewAny))->toBeFalse()
        ->and($registry->isFactoryLockException(Role::Directivo, FakePermission::ViewCampus))->toBeFalse()
        ->and($rules->grantProblem(Role::Directivo, FakePermission::ViewAny))->toBeNull()
        ->and($rules->lockProblem(FakePermission::ViewAny))->toBe('«fake.thing.view_any» es solo del admin: no se da a otro puesto.')
        ->and($rules->lockProblem(FakePermission::ViewAny, Role::Directivo))->toBeNull()
        ->and($rules->grantProblem(Role::Subdireccion, FakePermission::ViewAny))->toBe($rules->lockProblem(FakePermission::ViewAny))
        ->and($rules->grantProblem(Role::Coordinador, FakePermission::ViewAny))->toBe($rules->lockProblem(FakePermission::ViewAny));
});

it('CF-03: el registro rechaza un candado en grants() fuera de la lista de excepciones, y una excepción que no sea candado de lectura concedido a quien lee en todo el Tec', function (array $grants, array $adminOnly, array $exceptions, string $message) {
    expect(fn () => PermissionRegistry::fromDeclarations([FakePermission::class, CorePermission::class], $grants, [], $adminOnly, $exceptions))
        ->toThrow(LogicException::class, $message);
})->with([
    'candado concedido sin excepción' => [[Role::Directivo->value => [FakePermission::ViewAny]], [FakePermission::ViewAny], [], 'El permiso «fake.thing.view_any» es candado (CF-03): solo lo tiene el admin y no se concede a «directivo» en grants().'],
    'excepción para otro puesto' => [[Role::Directivo->value => [FakePermission::ViewAny]], [FakePermission::ViewAny], [Role::Subdireccion->value => [FakePermission::ViewAny]], 'La excepción de candado «fake.thing.view_any» de «subdireccion» no está concedida en grants().'],
    'excepción que no es candado' => [[Role::Directivo->value => [FakePermission::ViewAny]], [], [Role::Directivo->value => [FakePermission::ViewAny]], 'La excepción de candado «fake.thing.view_any» de «directivo» no es candado (CF-03).'],
    'excepción a quien no lee en todo el Tec' => [[Role::Subdireccion->value => [FakePermission::ViewAny]], [FakePermission::ViewAny], [Role::Subdireccion->value => [FakePermission::ViewAny]], 'La excepción de candado «fake.thing.view_any» es para «subdireccion», que no lee en todo el Tec (AZ-03).'],
    'excepción que escribe' => [[Role::JefeDepartamento->value => [CorePermission::OrgUnitCreate]], [CorePermission::OrgUnitCreate], [Role::JefeDepartamento->value => [CorePermission::OrgUnitCreate]], 'La excepción de candado «core.org_unit.create» no es de lectura.'],
]);

it('el registro da los candados, busca por clave y cambia de huella si cambia lo de fábrica', function () {
    $registry = PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => [FakePermission::ViewCampus]], [], [FakePermission::ViewAny]);
    $same = PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => [FakePermission::ViewCampus]], [], [FakePermission::ViewAny]);
    $other = PermissionRegistry::fromDeclarations([FakePermission::class], [Role::Coordinador->value => [FakePermission::ViewUnit]], [], [FakePermission::ViewAny]);

    expect($registry->adminOnly())->toBe(['fake.thing.view_any'])
        ->and($registry->isAdminOnly(FakePermission::ViewAny))->toBeTrue()
        ->and($registry->isAdminOnly(FakePermission::ViewCampus))->toBeFalse()
        ->and($registry->forKey('fake.thing.view_unit'))->toBe(FakePermission::ViewUnit)
        ->and($registry->forKey('nadie.declaro.esto'))->toBeNull()
        ->and($registry->keyOf(FakePermission::ViewOwn))->toBe('fake.thing.view_own')
        ->and($registry->fingerprint())->toBe($same->fingerprint())
        ->and($registry->fingerprint())->not->toBe($other->fingerprint());
});
