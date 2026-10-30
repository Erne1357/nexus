<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Database\Seeders\DatabaseSeeder;
use Database\Seeders\DemoSeeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Hash;

/*
| Catálogo demo (tarea 1.10 ampliada): departamentos, carreras con su oferta por campus, planes con su retícula,
| especialidades y seriación, los usuarios de prueba y los periodos, para probar las pantallas con datos
| realistas. DemoSeeder siembra Campus II; con los dos campus se siembran las 12 carreras de Campus I.
*/

/**
 * Los campus y el catálogo demo de `$campuses`, sin usuarios ni periodos.
 *
 * @param  list<string>  $campuses
 */
function seedDemoCatalog(array $campuses): void
{
    $seeder = (new DatabaseSeeder)->setContainer(app());

    $seeder->call(CampusSeeder::class);
    $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => $campuses]);
}

/**
 * Cuántos registros hay de lo que siembra el demo, contando los borrados.
 *
 * @return array<string, int>
 */
function demoCounts(): array
{
    return [
        ...collect([Department::class, Career::class, CareerCampus::class, StudyPlan::class, Subject::class, Specialty::class, StudyPlanSubject::class, SubjectPrerequisite::class, DepartmentHead::class])
            ->mapWithKeys(fn (string $model): array => [$model => $model::withTrashed()->count()])
            ->all(),
        'users' => User::query()->count(),
        'role_scopes' => RoleScope::query()->count(),
        'terms' => Term::query()->count(),
    ];
}

/**
 * La cadena de seriación más larga (en materias) de un plan, o null si la seriación forma un ciclo.
 *
 * @param  Collection<int, SubjectPrerequisite>  $seriation
 */
function longestSeriationChain(Collection $seriation): ?int
{
    $requires = $seriation->groupBy('subject_id')->map(fn (Collection $rows): array => $rows->pluck('prerequisite_subject_id')->all())->all();
    $depth = [];
    $visiting = [];

    $walk = function (string $subject) use (&$walk, &$depth, &$visiting, $requires): ?int {
        if (isset($depth[$subject])) {
            return $depth[$subject];
        }

        if (isset($visiting[$subject])) {
            return null;
        }

        $visiting[$subject] = true;
        $longest = 0;

        foreach ($requires[$subject] ?? [] as $prerequisite) {
            $chain = $walk($prerequisite);

            if ($chain === null) {
                return null;
            }

            $longest = max($longest, $chain);
        }

        unset($visiting[$subject]);

        return $depth[$subject] = $longest + 1;
    };

    $longest = 0;

    foreach (array_keys($requires) as $subject) {
        $chain = $walk($subject);

        if ($chain === null) {
            return null;
        }

        $longest = max($longest, $chain);
    }

    return $longest;
}

it('siembra las 6 carreras de Campus II con un plan vigente cada una', function () {
    $this->seed(DemoSeeder::class);

    $offered = CareerCampus::query()->whereRelation('campus', 'code', 'C2')->where('is_active', true)->with('career')->get();

    expect($offered->pluck('career.code')->all())->toEqualCanonicalizing(['LADM', 'COPU', 'ISIC', 'IIND', 'ILOG', 'IGEM'])
        ->and($offered->every(fn (CareerCampus $offering): bool => $offering->career->studyPlans()->where('is_active', true)->count() === 1))->toBeTrue()
        ->and(CareerCampus::query()->whereRelation('campus', 'code', 'C1')->exists())->toBeFalse();
});

it('con los dos campus siembra 12 carreras en C1 y 6 en C2', function () {
    seedDemoCatalog(['C1', 'C2']);

    $offered = fn (string $campus): int => CareerCampus::query()->whereRelation('campus', 'code', $campus)->where('is_active', true)->count();

    expect($offered('C1'))->toBe(12)
        ->and($offered('C2'))->toBe(6)
        ->and(StudyPlan::query()->where('is_active', true)->distinct()->count('career_id'))->toBe(12)
        ->and(StudyPlan::query()->where('is_active', true)->count())->toBe(12);
});

it('el tronco común se comparte entre planes', function () {
    seedDemoCatalog(['C1', 'C2']);

    $plansWith = fn (string $code): int => Subject::query()->where('code', $code)->sole()->studyPlans()->count();

    expect($plansWith('ACF-0901'))->toBeGreaterThanOrEqual(10)
        ->and($plansWith('ACA-0907'))->toBe(12);
});

it('toda seriación está en la retícula de su plan y no forma ciclos', function () {
    seedDemoCatalog(['C1', 'C2']);

    $semesterOf = StudyPlanSubject::query()->get()
        ->mapWithKeys(fn (StudyPlanSubject $placement): array => ["{$placement->study_plan_id}|{$placement->subject_id}" => $placement->semester]);
    $prerequisites = SubjectPrerequisite::query()->get();

    expect($prerequisites)->not->toBeEmpty();

    foreach ($prerequisites as $prerequisite) {
        $subject = $semesterOf["{$prerequisite->study_plan_id}|{$prerequisite->subject_id}"] ?? null;
        $required = $semesterOf["{$prerequisite->study_plan_id}|{$prerequisite->prerequisite_subject_id}"] ?? null;

        expect($subject)->not->toBeNull('La materia de una seriación no está en la retícula de su plan.')
            ->and($required)->not->toBeNull('El requisito de una seriación no está en la retícula de su plan.');

        $prerequisite->kind === PrerequisiteKind::Seriacion
            ? expect($required)->toBeLessThan($subject)
            : expect($required)->toBeLessThanOrEqual($subject);
    }

    $chains = $prerequisites->where('kind', PrerequisiteKind::Seriacion)->groupBy('study_plan_id')->map(longestSeriationChain(...));

    expect($chains->contains(null))->toBeFalse()
        ->and($chains->max())->toBeGreaterThanOrEqual(5)
        ->and($prerequisites->contains('kind', PrerequisiteKind::Correquisito))->toBeTrue();
});

it('cada plan vigente tiene 2 o 3 especialidades con materias de especialidad', function () {
    seedDemoCatalog(['C1', 'C2']);

    $plans = StudyPlan::query()->where('is_active', true)->with(['specialties', 'placements.subject'])->get();

    expect($plans)->toHaveCount(12);

    foreach ($plans as $plan) {
        expect($plan->specialties->count())->toBeBetween(2, 3, "{$plan->code} no tiene 2 o 3 especialidades.");

        foreach ($plan->specialties as $specialty) {
            $subjects = $plan->placements->where('specialty_id', $specialty->id)->pluck('subject');

            expect($subjects)->not->toBeEmpty("La especialidad {$specialty->code} de {$plan->code} no tiene materias.")
                ->and($subjects->every(fn (Subject $subject): bool => $subject->kind === SubjectKind::Especialidad))->toBeTrue();
        }
    }
});

it('la especialidad de una fila de la retícula es de su mismo plan', function () {
    seedDemoCatalog(['C1', 'C2']);

    $placements = StudyPlanSubject::withTrashed()->whereNotNull('specialty_id')->get();
    $planOf = Specialty::withTrashed()->pluck('study_plan_id', 'id');

    expect($placements)->not->toBeEmpty()
        ->and($placements->every(fn (StudyPlanSubject $placement): bool => $planOf[$placement->specialty_id] === $placement->study_plan_id))->toBeTrue();
});

it('los créditos de cada plan cuadran: las obligatorias sin especialidad más cualquiera de sus especialidades', function () {
    seedDemoCatalog(['C1', 'C2']);

    $plans = StudyPlan::query()->whereNotNull('total_credits')->has('placements')->with('placements.subject')->get();

    expect($plans->count())->toBeGreaterThan(12);

    foreach ($plans as $plan) {
        $required = $plan->placements->whereNull('specialty_id')->where('is_required', true)->sum('subject.credits');
        $specialties = $plan->placements->whereNotNull('specialty_id')->groupBy('specialty_id')->map->sum('subject.credits');

        foreach ($specialties->whenEmpty(fn (Collection $none): Collection => $none->push(0)) as $credits) {
            expect($required + $credits)->toBe($plan->total_credits, "Los créditos de {$plan->code} no cuadran con su retícula.");
        }
    }
});

it('deja los casos extremos para probar las pantallas', function () {
    $this->seed(DemoSeeder::class);

    expect(StudyPlan::query()->doesntHave('placements')->exists())->toBeTrue('Falta un plan sin retícula.')
        ->and(StudyPlan::query()->where('is_active', false)->has('placements')->exists())->toBeTrue('Falta un plan viejo inactivo con retícula.')
        ->and(StudyPlan::query()->where('semesters_count', '>', 9)->whereHas('placements', fn ($query) => $query->where('semester', '>', 9))->exists())->toBeTrue('Falta un plan de más de 9 semestres.')
        ->and(StudyPlanSubject::query()->whereRelation('subject', 'is_active', false)->exists())->toBeTrue('Falta una materia inactiva en una retícula.')
        ->and(Department::query()->where('is_active', false)->exists())->toBeTrue('Falta un departamento inactivo.')
        ->and(Career::query()->where('is_active', false)->exists())->toBeTrue('Falta una carrera inactiva.')
        ->and(Department::onlyTrashed()->exists())->toBeTrue('Falta un departamento borrado.')
        ->and(Subject::onlyTrashed()->exists())->toBeTrue('Falta una materia borrada.')
        ->and(Specialty::onlyTrashed()->exists())->toBeTrue('Falta una especialidad borrada.')
        ->and(StudyPlanSubject::onlyTrashed()->exists())->toBeTrue('Falta una materia quitada de una retícula.')
        ->and(SubjectPrerequisite::onlyTrashed()->exists())->toBeTrue('Falta una seriación quitada.')
        ->and(StudyPlanSubject::query()->where('is_required', false)->exists())->toBeTrue('Falta una materia optativa.')
        ->and(Subject::query()->whereNull('kind')->exists())->toBeTrue('Falta una materia sin tipo capturado.')
        ->and(Subject::query()->whereRaw('char_length(name) > 100')->exists())->toBeTrue('Falta una materia con nombre muy largo.');
});

it('crea los usuarios de prueba con su alcance', function () {
    $this->seed(DemoSeeder::class);

    $c2 = Campus::query()->where('code', 'C2')->sole();
    $isic = Career::query()->where('code', 'ISIC')->sole();
    $iind = Career::query()->where('code', 'IIND')->sole();
    $sc = Department::query()->where('code', 'SC')->sole();
    $context = fn (string $username): ScopedContext => app(ScopedContext::class)->forUser(User::query()->where('username', $username)->sole());
    $coordinator = User::query()->where('username', 'coord.isc.c2')->sole();

    expect(User::query()->where('username', 'like', '%.c2')->pluck('username')->all())->toEqualCanonicalizing([
        'subdir.c2',
        'jefe.cb.c2', 'jpd.cb.c2', 'jefe.cea.c2', 'jpd.cea.c2', 'jefe.ii.c2', 'jpd.ii.c2', 'jefe.sc.c2', 'jpd.sc.c2',
        'coord.isc.c2', 'coord.ii.c2', 'coord.ige.c2', 'coord.il.c2', 'coord.la.c2', 'coord.cp.c2',
    ])
        ->and($context('coord.isc.c2')->covers(Role::Coordinador, $c2, null, $isic->id))->toBeTrue()
        ->and($context('coord.isc.c2')->covers(Role::Coordinador, $c2, null, $iind->id))->toBeFalse()
        ->and($context('jefe.sc.c2')->covers(Role::JefeDepartamento, $c2, $sc->id))->toBeTrue()
        ->and($context('jpd.sc.c2')->covers(Role::JefeProyectoDocente, $c2, $sc->id))->toBeTrue()
        ->and($context('subdir.c2')->covers(Role::Subdireccion, $c2))->toBeTrue()
        ->and(DepartmentHead::query()->currentOn(now())->where(['department_id' => $sc->id, 'campus_id' => $c2->id, 'kind' => DepartmentHeadKind::Jefe])->sole()->user->username)->toBe('jefe.sc.c2')
        ->and(DepartmentHead::query()->currentOn(now())->where(['department_id' => $sc->id, 'campus_id' => $c2->id, 'kind' => DepartmentHeadKind::JefeProyectoDocente])->whereHas('user', fn ($query) => $query->where('username', 'jpd.sc.c2'))->sole()->user->username)->toBe('jpd.sc.c2')
        ->and(Hash::check('password', $coordinator->password))->toBeTrue()
        ->and($coordinator->must_change_password)->toBeFalse();
});

it('siembra los periodos 20261, 20263 y 20271, con Ene-Jun 2027 como actual', function () {
    $this->seed(DemoSeeder::class);

    expect(Term::query()->orderBy('code')->pluck('is_current', 'code')->all())->toBe(['20261' => false, '20263' => false, '20271' => true]);
});

it('no cambia el periodo actual si ya hay uno', function () {
    Term::factory()->current()->create(['code' => '20263', 'name' => 'Ago-Dic 2026', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);

    (new DemoTermSeeder)->run();

    expect(Term::query()->orderBy('code')->pluck('is_current', 'code')->all())->toBe(['20261' => false, '20263' => true, '20271' => false]);
});

it('es idempotente: sembrar dos veces no duplica', function () {
    $this->seed(DemoSeeder::class);
    $first = demoCounts();
    $this->seed(DemoSeeder::class);

    expect(demoCounts())->toBe($first)
        ->and($first[Subject::class])->toBeGreaterThan(0);
});

it('el camino del seeder masivo: el demo de Campus II y después los dos campus dejan datos consistentes', function () {
    $this->seed(DemoSeeder::class);

    $seedBothCampuses = function (): void {
        $seeder = (new DatabaseSeeder)->setContainer(app());
        $seeder->callWith(AcademicDemoSeeder::class, ['campuses' => ['C1', 'C2']]);
        $seeder->callWith(DemoStaffSeeder::class, ['campuses' => ['C1', 'C2']]);
    };

    $seedBothCampuses();
    $first = demoCounts();
    $seedBothCampuses();

    $offered = fn (string $campus): int => CareerCampus::query()->whereRelation('campus', 'code', $campus)->where('is_active', true)->count();
    $scopes = RoleScope::query()->get();
    $staff = User::query()->where('username', 'like', '%.c_')->get();
    $roleOf = ['subdir' => Role::Subdireccion, 'jefe' => Role::JefeDepartamento, 'jpd' => Role::JefeProyectoDocente, 'coord' => Role::Coordinador];
    $campusIds = Campus::query()->pluck('id', 'code');

    expect(demoCounts())->toBe($first)
        ->and($offered('C1'))->toBe(12)
        ->and($offered('C2'))->toBe(6)
        ->and($scopes->countBy(fn (RoleScope $scope): string => "{$scope->user_id}|{$scope->role->value}|{$scope->campus_id}|{$scope->department_id}|{$scope->career_id}")->max())->toBe(1)
        ->and($staff->filter(fn (User $user): bool => str_ends_with($user->username, '.c1')))->toHaveCount(25)
        ->and($staff->filter(fn (User $user): bool => str_ends_with($user->username, '.c2')))->toHaveCount(15)
        ->and(DepartmentHead::query()->currentOn(now())->whereHas('user', fn ($query) => $query->where('username', 'like', 'jefe.%')->orWhere('username', 'like', 'jpd.%'))->count())->toBe($staff->filter(fn (User $user): bool => preg_match('/^(jefe|jpd)\./', $user->username) === 1)->count());

    foreach ($staff as $user) {
        $role = $roleOf[strtok($user->username, '.')];
        $campus = $campusIds[strtoupper(substr($user->username, -2))];
        $context = app(ScopedContext::class)->forUser($user);

        expect($context->roles())->toBe([$role], "{$user->username} no ejerce su rol.")
            ->and($context->scopes()->pluck('campus_id')->all())->toBe([$campus], "{$user->username} no tiene un solo alcance en su campus.");
    }

    expect(app(ScopedContext::class)->forUser($staff->firstWhere('username', 'coord.ie.c1'))
        ->covers(Role::Coordinador, $campusIds['C1'], null, Career::query()->where('code', 'IELE')->value('id')))->toBeTrue();
});

it('completa el rol y el alcance de un usuario de prueba que una corrida anterior dejó a medias', function () {
    $this->seed(CampusSeeder::class);
    $c2 = Campus::query()->where('code', 'C2')->sole();

    User::factory()->create(['username' => 'coord.isc.c2']);
    User::factory()->withRole(Role::Subdireccion, ['campus_id' => $c2->id, 'valid_from' => '2026-01-01', 'valid_to' => '2026-06-30'])->create(['username' => 'subdir.c2']);

    $this->seed(DemoSeeder::class);
    $this->seed(DemoSeeder::class);

    $coordinator = User::query()->where('username', 'coord.isc.c2')->sole();
    $subdirection = User::query()->where('username', 'subdir.c2')->sole();

    expect(app(ScopedContext::class)->forUser($coordinator)->covers(Role::Coordinador, $c2, null, Career::query()->where('code', 'ISIC')->value('id')))->toBeTrue()
        ->and(app(ScopedContext::class)->forUser($subdirection)->covers(Role::Subdireccion, $c2))->toBeTrue()
        ->and($coordinator->roleScopes()->count())->toBe(1)
        ->and($subdirection->roleScopes()->count())->toBe(2);
});

it('no corre en producción', function () {
    $this->app->detectEnvironment(fn (): string => 'production');

    foreach ([DemoSeeder::class, DemoTermSeeder::class, AcademicDemoSeeder::class, DemoStaffSeeder::class] as $seeder) {
        expect(fn () => $this->app->make($seeder)->setContainer($this->app)->__invoke())
            ->toThrow(RuntimeException::class, 'producción');
    }

    expect(Department::withTrashed()->count())->toBe(0)
        ->and(User::query()->count())->toBe(0)
        ->and(Term::query()->count())->toBe(0);
});
