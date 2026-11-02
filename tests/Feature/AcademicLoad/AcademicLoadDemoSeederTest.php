<?php

use App\Modules\Academic\Database\Seeders\AcademicDemoSeeder;
use App\Modules\Academic\Database\Seeders\DemoStaffSeeder;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Database\Seeders\AcademicLoadDemoSeeder;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Enums\ProposalStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\AcademicLoad\Notifications\ProposalReturned;
use App\Modules\AcademicLoad\Notifications\ProposalSubmitted;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Database\Seeders\AdminUserSeeder;
use App\Modules\Core\Database\Seeders\CampusSeeder;
use App\Modules\Core\Database\Seeders\DemoTermSeeder;
use App\Modules\Core\Database\Seeders\RoleSeeder;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Database\Seeders\FacilitiesDemoSeeder;
use App\Modules\Faculty\Database\Seeders\FacultyDemoSeeder;
use App\Modules\Students\Database\Seeders\StudentsDemoSeeder;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Notifications\DatabaseNotification;

/*
| Propuestas y bandejas de prueba (tareas 2.7 a 2.9): el demo con el periodo 20271 abierto en C2 y los catálogos
| de Ciencias Básicas y Sistemas validados, como los deja el seeder de CatalogValidation; el seeder arma una
| propuesta por carrera ofrecida, con borrador, envíos, aceptaciones, regresos y sus avisos.
*/

beforeEach(function () {
    (new class extends Seeder
    {
        use WithoutModelEvents;

        public function run(): void
        {
            $this->call([RoleSeeder::class, CampusSeeder::class, AdminUserSeeder::class, DemoTermSeeder::class]);
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
    app(OpenTerm::class)->handle($this->term, $this->c2);

    CatalogReview::query()
        ->where('term_id', $this->term->id)
        ->whereIn('department_id', Department::query()->whereIn('code', ['CB', 'SC'])->select('id'))
        ->update(['status' => ReviewStatus::Validated]);
});

function seedProposals(): void
{
    (new AcademicLoadDemoSeeder)->setContainer(app())->run();
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

it('no corre en producción', function () {
    app()->detectEnvironment(fn (): string => 'production');

    expect(fn () => seedProposals())->toThrow(RuntimeException::class);
});
