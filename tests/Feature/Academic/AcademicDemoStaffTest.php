<?php

use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\FacilitiesPermission;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\Academic\DemoCatalog;

/*
| Catálogo demo (tarea 1.10 ampliada): departamentos, carreras con su oferta por campus, planes con su retícula,
| especialidades y seriación, los usuarios de prueba y los periodos, para probar las pantallas con datos
| realistas. DemoSeeder siembra Campus II; con los dos campus se siembran las 12 carreras de Campus I.
|
| Las pruebas del demo académico van en cinco archivos (AcademicDemoSeederTest, AcademicDemoCatalogTest,
| AcademicDemoStaffTest, AcademicDemoReseedTest y AcademicDemoMassiveTest) porque casi todas siembran el demo
| completo: juntas tardaban unos 10 minutos en un solo proceso y marcaban el tiempo de toda la suite. Separadas, se
| reparten entre los procesos y los shards del CI (docs/arquitectura/testing.md, «Shards»). Los atajos están en
| tests/Support/Academic/DemoCatalog.php.
|
| Este archivo: los usuarios de prueba con su rol y su alcance.
*/

it('crea los usuarios de prueba con su alcance: Subdirección y jefes de todo el Tec, jefes de proyecto docente y coordinadores en Presencial y por campus (AZ-S17)', function () {
    $this->seed(DemoSeeder::class);
    modalityOf('EAD')->update(['is_active' => true]);

    $c1 = Campus::query()->where('code', 'C1')->sole();
    $c2 = Campus::query()->where('code', 'C2')->sole();
    $isic = Career::query()->where('code', 'ISIC')->sole();
    $iind = Career::query()->where('code', 'IIND')->sole();
    $sc = Department::query()->where('code', 'SC')->sole();
    $context = fn (string $username): Actor => app(Actor::class)->forUser(User::query()->where('username', $username)->sole());
    $coordinator = User::query()->where('username', 'coord.isc.c2')->sole();

    expect(DemoCatalog::staff()->pluck('username')->all())->toEqualCanonicalizing([
        'subdir',
        'jefe.cb', 'jefe.cea', 'jefe.ii', 'jefe.sc',
        'jpd.cb.c2', 'jpd.cea.c2', 'jpd.ii.c2', 'jpd.sc.c2',
        'coord.isc.c2', 'coord.ii.c2', 'coord.ige.c2', 'coord.il.c2', 'coord.la.c2', 'coord.cp.c2',
    ])
        ->and($context('coord.isc.c2')->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: $isic->id)))->toBeTrue()
        ->and($context('coord.isc.c2')->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: $iind->id)))->toBeFalse()
        ->and($context('coord.isc.c2')->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: $isic->id)))->toBeTrue()
        ->and($context('jefe.sc')->allows(AcademicLoadPermission::LoadView, Target::of(modality: modalityOf('PRES')->id, unit: $sc->id)))->toBeTrue()
        ->and($context('jefe.sc')->heldScopes()->sole()->campus_id)->toBeNull()
        ->and($context('jpd.sc.c2')->allows(AcademicLoadPermission::LoadView, Target::of(modality: modalityOf('PRES')->id, unit: $sc->id)))->toBeTrue()
        ->and($context('jpd.sc.c2')->allows(AcademicLoadPermission::LoadView, Target::of(modality: modalityOf('EAD')->id, unit: $sc->id)))->toBeFalse()
        ->and($context('subdir')->allows(FacilitiesPermission::BuildingView, Target::of(campus: $c2->id)))->toBeTrue()
        ->and($context('subdir')->allows(FacilitiesPermission::BuildingView, Target::of(campus: $c1->id)))->toBeTrue()
        ->and($context('subdir')->heldScopes()->sole()->campus_id)->toBeNull()
        ->and(RoleAssignment::query()->current()->where(['org_unit_id' => $sc->id, 'role' => Role::JefeDepartamento])->sole())
        ->campus_id->toBeNull()
        ->user->username->toBe('jefe.sc')
        ->and(RoleAssignment::query()->current()->where(['org_unit_id' => $sc->id, 'campus_id' => $c2->id, 'role' => Role::JefeProyectoDocente])->whereHas('user', fn ($query) => $query->where('username', 'jpd.sc.c2'))->exists())->toBeTrue()
        ->and(RoleAssignment::query()->whereIn('role', [Role::Coordinador, Role::JefeProyectoDocente])->get())
        ->each(fn ($assignment) => $assignment->modality_id->toBe(modalityOf('PRES')->id)->campus_id->toBe($c2->id))
        ->and(DB::table('department_heads')->count())->toBe(0)
        ->and(Hash::check('password', $coordinator->password))->toBeTrue()
        ->and($coordinator->must_change_password)->toBeFalse();
});

it('completa el rol y el alcance de un usuario de prueba que una corrida anterior dejó a medias', function () {
    $this->seed(CampusSeeder::class);
    $c2 = Campus::query()->where('code', 'C2')->sole();

    User::factory()->create(['username' => 'coord.isc.c2']);
    User::factory()->withRole(Role::Subdireccion, ['valid_from' => '2026-01-01', 'valid_to' => '2026-06-30'])->create(['username' => 'subdir']);

    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $coordinator = User::query()->where('username', 'coord.isc.c2')->sole();
    $subdirection = User::query()->where('username', 'subdir')->sole();

    expect(app(Actor::class)->forUser($coordinator)->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: Career::query()->where('code', 'ISIC')->value('id'))))->toBeTrue()
        ->and(app(Actor::class)->forUser($subdirection)->allows(FacilitiesPermission::BuildingView, Target::of(campus: $c2->id)))->toBeTrue()
        ->and($coordinator->roleAssignments()->count())->toBe(1)
        ->and($subdirection->roleAssignments()->count())->toBe(2);
});

it('con todos los departamentos nombra jefe también a los que no tienen carreras ni materias en el campus (demo por puntos, CV-S1)', function () {
    DemoCatalog::seed(['C2']);

    (new DatabaseSeeder)->setContainer(app())->callWith(DemoStaffSeeder::class, ['campuses' => ['C2'], 'allDepartments' => true]);

    $heads = RoleAssignment::query()->current()->where('role', Role::JefeDepartamento)->pluck('org_unit_id');

    expect(Department::query()->where('is_active', true)->pluck('id')->diff($heads)->all())->toBe([])
        ->and(DemoCatalog::staff()->pluck('username')->all())->toContain('jefe.ee', 'jefe.mm')
        ->and(DemoCatalog::staff()->pluck('username')->filter(fn (string $username): bool => str_starts_with($username, 'jpd.'))->sort()->values()->all())
        ->toBe(['jpd.cb.c2', 'jpd.cea.c2', 'jpd.ii.c2', 'jpd.sc.c2']);
});
