<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\CatalogValidation\Database\Seeders\CatalogValidationDemoSeeder;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewFlag;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Models\CatalogReviewFlag;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use Database\Seeders\DatabaseSeeder;
use Spatie\Activitylog\Models\Activity;

/*
| Validación de catálogos demo y masiva (tareas 2.4 a 2.6): el seeder trabaja sobre los periodos ya abiertos, valida
| los anteriores para dejar su foto, simula los cambios y deja el último periodo con estados fijos por departamento
| (de los que depende el seeder de propuestas), con observaciones y recordatorios. CV3, CV4, CV5.
*/

/**
 * Seis departamentos con jefe, docentes, materias, carrera y salones en cada campus, y los periodos 20263 y 20271
 * abiertos con OpenTerm en Presencial (M4: la apertura es por modalidad; los campus son solo el lugar, MS3).
 *
 * @param  list<string>  $campuses
 */
function seedCatalogScenario(array $campuses = ['C1', 'C2'], bool $previous = true): void
{
    $admin = User::factory()->admin()->create(['username' => 'admin']);
    test()->actingAs($admin);

    $models = collect($campuses)->map(fn (string $code): Campus => Campus::factory()->create(['code' => $code]));
    $buildings = $models->map(fn (Campus $campus): Building => Building::factory()->create(['campus_id' => $campus->id]));

    foreach (['CB', 'CEA', 'II', 'SC', 'EE', 'MM'] as $code) {
        $department = Department::factory()->create(['code' => $code, 'name' => "Departamento {$code}"]);
        User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(['name' => "Jefe {$code}"]);
        Teacher::factory()->count(10)->adscribedTo($department)->create();
        Teacher::factory()->count(2)->inactive()->adscribedTo($department)->create();
        Subject::factory()->count(4)->create(['department_id' => $department->id]);
        Career::factory()->create(['department_id' => $department->id]);

        foreach ($buildings as $building) {
            Room::factory()->count(6)->create(['building_id' => $building->id, 'department_id' => $department->id]);
        }
    }

    $codes = $previous ? ['20263', '20271'] : ['20271'];

    foreach ($codes as $code) {
        $year = (int) substr($code, 0, 4);
        $term = Term::factory()->create([
            'code' => $code,
            'name' => "Periodo {$code}",
            'starts_on' => $year.($code[4] === '3' ? '-08-17' : '-01-25'),
            'ends_on' => $year.($code[4] === '3' ? '-12-11' : '-06-11'),
        ]);
        app(OpenTerm::class)->handle($term, modalityOf('PRES'));
    }
}

function runCatalogDemoSeeder(string $profile = 'demo', string $mode = 'mixed'): void
{
    (new DatabaseSeeder)->setContainer(app())->callWith(CatalogValidationDemoSeeder::class, ['profile' => $profile, 'mode' => $mode]);
}

function cvReview(string $term, string $department, Catalog $catalog): CatalogReview
{
    return CatalogReview::query()
        ->whereRelation('term', 'code', $term)
        ->whereRelation('department', 'code', $department)
        ->where('catalog', $catalog)
        ->sole();
}

it('rechaza correr en producción', function () {
    app()->detectEnvironment(fn () => 'production');

    expect(fn () => runCatalogDemoSeeder())->toThrow(RuntimeException::class, 'producción');
});

it('no hace nada si no hay periodos abiertos', function () {
    User::factory()->admin()->create(['username' => 'admin']);
    Term::factory()->create(['code' => '20271']);

    runCatalogDemoSeeder('massive');

    expect(CatalogReview::count())->toBe(0)->and(CatalogReviewFlag::count())->toBe(0)->and(Activity::query()->where('event', 'reminded')->count())->toBe(0);
});

it('deja el periodo actual con los estados fijos por departamento (HC-14)', function () {
    seedCatalogScenario();
    $query = app(CatalogReviewQuery::class);
    $term = Term::query()->where('code', '20271')->firstOrFail();
    $pending = fn (string $department, string $campus): array => array_map(
        fn (Catalog $catalog): string => $catalog->value,
        $query->pendingFor($term, modalityOf('PRES'), Department::query()->where('code', $department)->firstOrFail()),
    );

    runCatalogDemoSeeder('massive');

    foreach (['CB', 'SC', 'II', 'EE', 'MM'] as $code) {
        expect($pending($code, 'C2'))->toBe([])->and($pending($code, 'C1'))->toBe([]);
    }

    expect($pending('CEA', 'C2'))->toBe(['rooms', 'study_plans', 'careers'])
        ->and(cvReview('20271', 'CEA', Catalog::Rooms)->status)->toBe(ReviewStatus::InReview)
        ->and(cvReview('20271', 'EE', Catalog::Rooms)->status)->toBe(ReviewStatus::Validated)
        ->and(cvReview('20271', 'MM', Catalog::Teachers)->status)->toBe(ReviewStatus::Validated)
        ->and(cvReview('20271', 'CEA', Catalog::Careers)->status)->toBe(ReviewStatus::InReview)
        ->and(cvReview('20271', 'CEA', Catalog::Careers)->validated_by)->toBeNull()
        ->and(cvReview('20271', 'CEA', Catalog::Subjects)->status)->toBe(ReviewStatus::Validated)
        ->and(cvReview('20271', 'CEA', Catalog::StudyPlans)->status)->toBe(ReviewStatus::Pending)
        ->and(Activity::query()->where('event', 'reopened')->count())->toBe(1);
});

it('con el perfil demo deja CEA y EE validados y MM reabierto con salones en revisión (HC-14)', function () {
    seedCatalogScenario();
    $query = app(CatalogReviewQuery::class);
    $term = Term::query()->where('code', '20271')->firstOrFail();
    $pending = fn (string $department): array => $query->pendingFor($term, modalityOf('PRES'), Department::query()->where('code', $department)->firstOrFail());

    runCatalogDemoSeeder('demo');

    expect($pending('EE'))->toBe([])
        ->and($pending('CEA'))->toBe([])
        ->and(cvReview('20271', 'CEA', Catalog::Rooms)->status)->toBe(ReviewStatus::Validated)
        ->and(cvReview('20271', 'MM', Catalog::Rooms)->status)->toBe(ReviewStatus::InReview)
        ->and(CatalogReviewFlag::query()->whereRelation('review', 'catalog', Catalog::Rooms)->whereRelation('review.department', 'code', 'MM')->exists())->toBeTrue()
        ->and($pending('MM'))->toBe([Catalog::Teachers, Catalog::Rooms, Catalog::StudyPlans, Catalog::Careers])
        ->and(cvReview('20271', 'MM', Catalog::Teachers)->status)->toBe(ReviewStatus::InReview)
        ->and(cvReview('20271', 'MM', Catalog::Teachers)->validated_by)->toBeNull()
        ->and(cvReview('20271', 'MM', Catalog::Subjects)->status)->toBe(ReviewStatus::Validated)
        ->and(cvReview('20271', 'CEA', Catalog::Careers)->status)->toBe(ReviewStatus::Validated)
        ->and(Activity::query()->where('event', 'reopened')->count())->toBe(2);
});

it('con el perfil demo en un solo campus deja los mismos estados en C2', function () {
    seedCatalogScenario(['C2'], previous: false);

    runCatalogDemoSeeder('demo');

    $query = app(CatalogReviewQuery::class);
    $presencial = modalityOf('PRES');
    $term = Term::query()->where('code', '20271')->firstOrFail();

    expect($query->pendingFor($term, $presencial, Department::query()->where('code', 'CB')->firstOrFail()))->toBe([])
        ->and($query->pendingFor($term, $presencial, Department::query()->where('code', 'EE')->firstOrFail()))->toBe([])
        ->and($query->pendingFor($term, $presencial, Department::query()->where('code', 'MM')->firstOrFail()))->toBe([Catalog::Teachers, Catalog::Rooms, Catalog::StudyPlans, Catalog::Careers])
        ->and(CatalogReview::query()->whereNotNull('snapshot')->count())->toBe(CatalogReview::query()->where('status', ReviewStatus::Validated)->count() + Activity::query()->where('event', 'reopened')->count());
});

it('valida el periodo anterior con la foto y el actual cuenta altas y bajas contra ella (CV3)', function () {
    seedCatalogScenario();

    runCatalogDemoSeeder('massive');

    $previous = cvReview('20263', 'CB', Catalog::Teachers);
    $query = app(CatalogReviewQuery::class);

    expect(CatalogReview::query()->whereRelation('term', 'code', '20263')->where('status', '!=', ReviewStatus::Validated)->count())->toBe(0)
        ->and($previous->snapshot)->not->toBeEmpty()
        ->and($previous->validator->name)->toBe('Jefe CB');

    foreach ([Catalog::Teachers, Catalog::Subjects, Catalog::Rooms] as $catalog) {
        $summary = $query->summary(cvReview('20271', 'CB', $catalog));

        expect($summary['added'])->not->toBeEmpty("altas de {$catalog->value}");

        if ($catalog !== Catalog::Subjects) {
            expect($summary['removed'])->not->toBeEmpty("bajas de {$catalog->value}");
        }
    }
});

it('pone observaciones de todos los tipos que admite cada catálogo, algunas resueltas, y recordatorios (CV4)', function () {
    seedCatalogScenario();

    runCatalogDemoSeeder('massive');

    $rooms = CatalogReviewFlag::query()->whereRelation('review', 'catalog', Catalog::Rooms)->get()->pluck('flag')->unique();
    $others = CatalogReviewFlag::query()->whereRelation('review', 'catalog', '!=', Catalog::Rooms)->get()->pluck('flag')->unique();
    $reminded = Activity::query()->where('event', 'reminded')->get()->map(fn (Activity $activity) => $activity->subject->code)->sort()->values()->all();

    expect($rooms->count())->toBe(count(ReviewFlag::cases()))
        ->and($others->contains(ReviewFlag::WrongCapacity))->toBeFalse()
        ->and($others->count())->toBe(count(ReviewFlag::cases()) - 1)
        ->and(CatalogReviewFlag::whereNotNull('resolved_at')->count())->toBeGreaterThan(0)
        ->and(CatalogReviewFlag::whereNull('resolved_at')->count())->toBeGreaterThan(20)
        ->and(CatalogReviewFlag::query()->whereRelation('review.department', 'code', 'CB')->whereNotNull('resolved_at')->exists())->toBeTrue()
        ->and($reminded)->toBe(['CEA']);
});

it('es idempotente: correrlo dos veces no duplica observaciones, recordatorios ni altas', function () {
    seedCatalogScenario();
    runCatalogDemoSeeder('massive');

    $count = fn (): array => [
        CatalogReviewFlag::count(),
        Activity::query()->where('event', 'reminded')->count(),
        Activity::query()->where('event', 'validated')->count(),
        Teacher::withTrashed()->count(),
        Subject::withTrashed()->count(),
        Room::withTrashed()->count(),
    ];
    $first = $count();

    runCatalogDemoSeeder('massive');

    expect($count())->toBe($first);
});

it('el resumen del periodo actual muestra exactamente las bajas y las reactivaciones sembradas', function () {
    seedCatalogScenario();
    $department = Department::query()->where('code', 'CB')->firstOrFail();
    $wasInactive = Teacher::query()->where('is_active', false)->whereHas('adscriptions', fn ($query) => $query->where('department_id', $department->id))->pluck('rfc')->all();

    runCatalogDemoSeeder('massive');

    $summary = app(CatalogReviewQuery::class)->summary(cvReview('20271', 'CB', Catalog::Teachers));
    $added = array_column($summary['added'], 'code');
    $removed = array_column($summary['removed'], 'code');

    expect($removed)->toHaveCount(6)
        ->and($added)->toHaveCount(7)
        ->and(array_intersect($wasInactive, $added))->toHaveCount(2)
        ->and(array_intersect($removed, $wasInactive))->toBe([])
        ->and(array_intersect($removed, $added))->toBe([]);
});

it('no desactiva a los jefes ni a los jefes de proyecto docente', function () {
    seedCatalogScenario();
    $kept = [];

    foreach (Department::query()->orderBy('code')->get() as $department) {
        foreach ([null, 'project'] as $kind) {
            // El escenario ya nombra un jefe por departamento (uno solo vigente, AZ-S7): el docente es ese usuario.
            $user = $kind === null
                ? User::query()->whereIn('id', RoleAssignment::query()->where(['role' => Role::JefeDepartamento, 'org_unit_id' => $department->id])->select('user_id'))->sole()
                : User::factory()->create();
            $teacher = Teacher::factory()->adscribedTo($department)->create(['rfc' => 'A'.count($kept).'000', 'user_id' => $user->id]);

            if ($kind !== null) {
                RoleAssignment::factory()->create(['user_id' => $user->id, 'org_unit_id' => $department->id, 'campus_id' => Campus::query()->where('code', 'C2')->value('id'), 'role' => Role::JefeProyectoDocente, 'valid_from' => today()->subMonth()->toDateString(), 'valid_to' => null]);
            }

            $kept[] = $teacher->id;
        }
    }

    runCatalogDemoSeeder('massive');

    expect(Teacher::query()->whereIn('id', $kept)->where('is_active', true)->count())->toBe(count($kept))
        ->and(RoleAssignment::query()->whereIn('role', Role::HEADSHIPS)->whereNull('valid_to')->count())->toBe(count($kept))
        ->and(User::query()->whereIn('id', Teacher::query()->whereIn('id', $kept)->select('user_id'))->where('is_active', true)->count())->toBe(count($kept));
});

it('reparte las observaciones en registros distintos', function () {
    seedCatalogScenario();

    runCatalogDemoSeeder('massive');

    $flags = CatalogReviewFlag::query()->get();

    expect($flags->groupBy(fn (CatalogReviewFlag $flag): string => $flag->review_id.'/'.$flag->flaggable_id)->filter(fn ($same) => $same->count() > 1)->count())->toBe(0);
});

it('en modo previous valida el periodo anterior y simula los cambios sin dar de baja más que aulas, y deja todo el actual pendiente (demo por puntos, punto 2)', function () {
    seedCatalogScenario();
    Room::query()->orderBy('code')->limit(10)->update(['type' => RoomType::Laboratorio->value]);
    $labs = Room::query()->where('type', RoomType::Laboratorio->value)->where('is_active', true)->count();

    runCatalogDemoSeeder('massive', 'previous');

    $current = CatalogReview::query()->whereRelation('term', 'code', '20271');

    expect(CatalogReview::query()->whereRelation('term', 'code', '20263')->where('status', '!=', ReviewStatus::Validated)->count())->toBe(0)
        ->and((clone $current)->count())->toBeGreaterThan(0)
        ->and((clone $current)->where('status', '!=', ReviewStatus::Pending)->count())->toBe(0)
        ->and(app(CatalogReviewQuery::class)->summary(cvReview('20271', 'CB', Catalog::Teachers))['added'])->not->toBeEmpty()
        ->and(CatalogReviewFlag::count())->toBe(0)
        ->and(Activity::query()->where('event', 'reminded')->count())->toBe(0)
        ->and(Room::query()->where('is_active', false)->count())->toBeGreaterThan(0)
        ->and(Room::query()->where('type', RoomType::Laboratorio->value)->where('is_active', true)->count())->toBe($labs);
});

it('en modo validated empieza y valida cada revisión del periodo actual con el jefe de su departamento, sin observaciones (demo por puntos, punto 3)', function () {
    seedCatalogScenario(previous: false);

    runCatalogDemoSeeder('demo', 'validated');

    $reviews = CatalogReview::query()->whereRelation('term', 'code', '20271')->with(['department', 'validator'])->get();

    expect($reviews)->not->toBeEmpty()
        ->and($reviews->every(fn (CatalogReview $review): bool => $review->status === ReviewStatus::Validated && $review->validator->name === "Jefe {$review->department->code}"))->toBeTrue()
        ->and(Activity::query()->where('description', 'like', 'Empezó la revisión de %')->count())->toBe($reviews->count())
        ->and(CatalogReviewFlag::count())->toBe(0);
});

it('rechaza un modo que no existe', function () {
    expect(fn () => runCatalogDemoSeeder('demo', 'todo'))->toThrow(RuntimeException::class, 'Modo desconocido');
});
