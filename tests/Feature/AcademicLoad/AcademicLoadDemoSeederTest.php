<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\AcademicLoadServiceProvider;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Demo\DemoCheck;
use App\Modules\Core\Demo\DemoContext;
use App\Modules\Core\Demo\DemoPlan;
use App\Modules\Core\Enums\DemoCheckpoint;
use App\Modules\Core\Enums\DemoVolume;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Scheduling\SchedulingServiceProvider;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Event;

/*
| Propuestas y bandejas de prueba (tareas 2.7 a 2.9): el demo con el periodo 20271 abierto en Presencial (M4) y los catálogos
| de Ciencias Básicas y Sistemas validados, como los deja el seeder de CatalogValidation; el seeder arma una
| propuesta por carrera ofrecida, con borrador, envíos, aceptaciones, regresos y sus avisos.
*/

beforeEach(function () {
    (new class extends Seeder
    {
        use WithoutModelEvents;

        public function run(): void
        {
            $this->call([CampusSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class]);
            $this->callWith(AcademicDemoSeeder::class, ['campuses' => ['C2']]);
            $this->callWith(DemoStaffSeeder::class, ['campuses' => ['C2']]);
            $this->callWith(FacilitiesDemoSeeder::class, ['campuses' => ['C2']]);
            $this->callWith(FacultyDemoSeeder::class, ['teachersPerCampus' => ['C2' => 15]]);
            $this->callWith(StudentsDemoSeeder::class, ['studentsPerOffering' => ['C2' => 8]]);
        }
    })->setContainer(app())->__invoke();

    $this->term = Term::query()->where('code', '20271')->firstOrFail();
    $this->c2 = Campus::query()->where('code', 'C2')->firstOrFail();
    $this->actingAs(User::query()->where('username', 'admin')->firstOrFail());
    app(OpenTerm::class)->handle($this->term, modalityOf('PRES'));
    app(OpenCatalogValidation::class)->handle($this->term, Catalog::cases(), User::query()->where('username', 'admin')->firstOrFail());

    CatalogReview::query()
        ->where('term_id', $this->term->id)
        ->whereIn('department_id', Department::query()->whereIn('code', ['CB', 'SC'])->select('id'))
        ->update(['status' => ReviewStatus::Validated]);
});

function seedProposals(array $params = []): void
{
    (new AcademicLoadDemoSeeder)->setContainer(app())->run(...$params);
}

function validateEveryCatalogReview(Term $term): void
{
    CatalogReview::query()->where('term_id', $term->id)->update(['status' => ReviewStatus::Validated]);
}

it('arma una propuesta por carrera ofrecida en C2, con borrador y propuestas enviadas, regresadas o aceptadas', function () {
    seedProposals();

    $offered = CareerCampus::query()->where('campus_id', $this->c2->id)->where('is_active', true)->count();
    $statuses = CareerProposal::query()->where('term_id', $this->term->id)->pluck('status')->map->value->unique()->sort()->values()->all();

    expect(CareerProposal::query()->where('term_id', $this->term->id)->where('campus_id', $this->c2->id)->count())->toBe($offered)
        ->and($statuses)->toContain(ProposalStatus::Draft->value)
        ->and(array_intersect($statuses, [ProposalStatus::Submitted->value, ProposalStatus::Returned->value, ProposalStatus::Accepted->value]))->not->toBeEmpty()
        ->and(ProposalReview::query()->where('status', 'accepted')->exists())->toBeTrue()
        ->and(ProposalReview::query()->where('status', 'returned')->exists())->toBeTrue();
});

it('solo acepta partes de departamentos con sus catálogos validados, y su carga sale de borrador', function () {
    seedProposals();

    $accepted = ProposalReview::query()->where('status', 'accepted')->with('department')->get()->pluck('department.code')->unique()->values()->all();
    $proposed = AcademicLoad::query()->where('term_id', $this->term->id)->where('status', LoadStatus::Proposed)->with('department')->get()->pluck('department.code')->all();

    expect(array_diff($accepted, ['CB', 'SC']))->toBe([])
        ->and($proposed)->not->toBeEmpty()
        ->and(array_diff($proposed, ['CB', 'SC']))->toBe([]);
});

it('deja los avisos de propuesta enviada y regresada en la campana, con los más viejos leídos', function () {
    seedProposals();

    expect(DatabaseNotification::query()->where('type', ProposalSubmitted::class)->exists())->toBeTrue()
        ->and(DatabaseNotification::query()->where('type', ProposalReturned::class)->exists())->toBeTrue()
        ->and(DatabaseNotification::query()->whereNotNull('read_at')->exists())->toBeTrue()
        ->and(DatabaseNotification::query()->whereNull('read_at')->exists())->toBeTrue();
});

it('volver a correrlo no duplica propuestas, partes ni avisos', function () {
    seedProposals();
    $before = [CareerProposal::query()->count(), ProposalReview::query()->count(), DatabaseNotification::query()->count()];

    seedProposals();

    expect([CareerProposal::query()->count(), ProposalReview::query()->count(), DatabaseNotification::query()->count()])->toBe($before);
});

describe('completo (punto 4 de la demo)', function () {
    it('cada carrera ofrecida envía y cada departamento acepta todas sus partes; sus cargas pasan a propuesta', function () {
        validateEveryCatalogReview($this->term);

        seedProposals(['complete' => true]);

        $offered = CareerCampus::query()->where('campus_id', $this->c2->id)->where('is_active', true)->count();
        $withParts = ProposalReview::query()->distinct()->pluck('department_id');

        expect(CareerProposal::query()->where('campus_id', $this->c2->id)->where('status', ProposalStatus::Accepted)->count())->toBe($offered)
            ->and(ProposalReview::query()->where('status', '!=', 'accepted')->count())->toBe(0)
            ->and(AcademicLoad::query()->where('modality_id', $this->c2->modality_id)->whereIn('department_id', $withParts)->where('status', '!=', LoadStatus::Proposed)->count())->toBe(0)
            ->and(AcademicLoad::query()->whereNotIn('department_id', $withParts)->where('status', '!=', LoadStatus::Draft)->count())->toBe(0)
            // M1: abrir Presencial creó una carga por departamento, con los grupos de C1 y C2; no hay otras.
            ->and(AcademicLoad::query()->where('modality_id', '!=', $this->c2->modality_id)->count())->toBe(0);
    });

    it('termina lo que dejó la demo mezclada: reenvía las regresadas y acepta las pendientes', function () {
        validateEveryCatalogReview($this->term);
        seedProposals();
        expect(ProposalReview::query()->where('status', '!=', 'accepted')->exists())->toBeTrue();

        seedProposals(['complete' => true]);

        expect(CareerProposal::query()->where('status', '!=', ProposalStatus::Accepted)->count())->toBe(0)
            ->and(ProposalReview::query()->where('status', '!=', 'accepted')->count())->toBe(0);
    });

    it('se detiene con el motivo si un departamento no tiene sus catálogos validados (HC-14)', function () {
        expect(fn () => seedProposals(['complete' => true]))->toThrow(RuntimeException::class, 'no se pudo aceptar');
    });

    it('se detiene si el periodo no está abierto en la modalidad del campus', function () {
        expect(fn () => seedProposals(['complete' => true, 'campuses' => ['EAD']]))->toThrow(RuntimeException::class, 'no está abierto en EAD');
    });
});

it('demo por puntos: los pasos que llevan a «horarios» dejan todas sus revisiones en verde', function () {
    validateEveryCatalogReview($this->term);
    $plan = app(DemoPlan::class);
    $demo = DemoContext::for(DemoVolume::Demo);

    Event::fakeFor(function () use ($plan, $demo): void {
        foreach ($plan->stepsFor(DemoCheckpoint::Scheduling) as $step) {
            ($step->run)($demo);
        }
    }, $plan->quietEvents());

    // Solo las revisiones de AcademicLoad y Scheduling: las de los puntos anteriores las cubren sus módulos y esta base
    // no pasa por ellos (por ejemplo, la contraseña demo del admin).
    $checks = DemoPlan::fromModules([app()->getProvider(AcademicLoadServiceProvider::class), app()->getProvider(SchedulingServiceProvider::class)])
        ->checksAt(DemoCheckpoint::Scheduling);
    $failed = collect($checks)
        ->map(fn (DemoCheck $check): string|true => ($check->passes)($demo) === true ? true : $check->label.': '.($check->passes)($demo))
        ->reject(fn (string|true $result): bool => $result === true)->values()->all();

    expect($plan->stepsFor(DemoCheckpoint::Scheduling))->not->toBeEmpty()
        ->and($checks)->toHaveCount(5)
        ->and($failed)->toBe([]);
});

it('demo por puntos: una carga se compara con las partes de su modalidad en los campus de la demo (M1)', function () {
    validateEveryCatalogReview($this->term);
    seedProposals(['complete' => true]);
    $teaching = ProposalReview::query()->value('department_id');
    // M1: una sola carga presencial del departamento, con los grupos de C1 y C2; las partes son de C2.
    expect(AcademicLoad::query()->where('term_id', $this->term->id)->where('department_id', $teaching)->sole()->status)->toBe(LoadStatus::Proposed);
    $check = collect(app()->getProvider(AcademicLoadServiceProvider::class)->demoChecks())
        ->first(fn (DemoCheck $check): bool => $check->label === 'Las cargas con partes aceptadas están en propuesta');

    expect(($check->passes)(new DemoContext(DemoVolume::Massive, ['C1', 'C2'])))->toBeTrue()
        ->and(($check->passes)(new DemoContext(DemoVolume::Massive, ['C1'])))->toBeTrue();

    AcademicLoad::query()->where('modality_id', $this->c2->modality_id)->where('department_id', $teaching)->update(['status' => LoadStatus::Draft]);

    expect(($check->passes)(new DemoContext(DemoVolume::Massive, ['C1', 'C2'])))->toBe('1 cargas con partes aceptadas no están en propuesta.');
});

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => seedProposals())->toThrow(RuntimeException::class);
});
