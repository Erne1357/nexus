<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;
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
| Este archivo: el camino del seeder masivo (el demo de Campus II y después los dos campus, dos veces). Va solo porque
| es la prueba más lenta de la suite: en el CI tarda unos 8 minutos.
*/

it('el camino del seeder masivo: el demo de Campus II y después los dos campus dejan datos consistentes', function () {
    $this->seed(DemoSeeder::class);

    $seedBothCampuses = function (): void {
        $seeder = (new DatabaseSeeder)->setContainer(app());
        $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
        $seeder->callWith(DemoStaffSeeder::class, ['campuses' => ['C1', 'C2']]);
    };

    $seedBothCampuses();
    $first = DemoCatalog::counts();
    $seedBothCampuses();

    $offered = fn (string $campus): int => CareerCampus::query()->whereRelation('campus', 'code', $campus)->where('is_active', true)->count();
    $scopes = RoleAssignment::query()->get();
    $staff = DemoCatalog::staff();
    $roleOf = ['subdir' => Role::Subdireccion, 'jefe' => Role::JefeDepartamento, 'jpd' => Role::JefeProyectoDocente, 'coord' => Role::Coordinador];
    $campusIds = Campus::query()->pluck('id', 'code');
    $named = fn (string $pattern): Collection => $staff->filter(fn (User $user): bool => preg_match($pattern, $user->username) === 1);
    $headed = fn (Role $role): array => RoleAssignment::query()->current()->where('role', $role)->whereIn('user_id', $staff->modelKeys())->with('user')->get()->map(fn (RoleAssignment $head): string => $head->user->username)->all();

    expect(DemoCatalog::counts())->toBe($first)
        ->and($offered('C1'))->toBe(12)
        ->and($offered('C2'))->toBe(6)
        ->and($scopes->countBy(fn (RoleAssignment $scope): string => "{$scope->user_id}|{$scope->role->value}|{$scope->campus_id}|{$scope->org_unit_id}|{$scope->career_id}")->max())->toBe(1)
        ->and($named('/^subdir$/'))->toHaveCount(1)
        ->and($named('/^jpd\.[a-z]+\.c1$/'))->toHaveCount(6)
        ->and($named('/^jpd\.[a-z]+\.c2$/'))->toHaveCount(4)
        ->and($named('/^coord\.[a-z]+\.c1$/'))->toHaveCount(12)
        ->and($named('/^coord\.[a-z]+\.c2$/'))->toHaveCount(6)
        ->and($named('/^jefe\.[a-z]+$/')->pluck('username')->map(fn (string $username): string => substr($username, 5))->sort()->values()->all())
        ->toBe($named('/^jpd\./')->pluck('username')->map(fn (string $username): string => explode('.', $username)[1])->unique()->sort()->values()->all())
        ->and($headed(Role::JefeDepartamento))->toEqualCanonicalizing($named('/^jefe\./')->pluck('username')->all())
        ->and($headed(Role::JefeProyectoDocente))->toEqualCanonicalizing($named('/^jpd\./')->pluck('username')->all());

    foreach ($staff as $user) {
        $role = $roleOf[strtok($user->username, '.')];
        $campus = in_array($role, [Role::Subdireccion, Role::JefeDepartamento], true) ? null : $campusIds[strtoupper(substr($user->username, -2))];
        $context = app(Actor::class)->forUser($user);

        expect($context->heldRoles())->toBe([$role], "{$user->username} no ejerce su rol.")
            ->and($context->heldScopes()->pluck('campus_id')->all())->toBe([$campus], "{$user->username} no tiene un solo alcance donde le toca.");
    }

    expect(app(Actor::class)->forUser($staff->firstWhere('username', 'coord.ie.c1'))
        ->allows(AcademicLoadPermission::ProposalView, Target::of(modality: modalityOf('PRES')->id, career: Career::query()->where('code', 'IELE')->value('id'))))->toBeTrue();
});
