<?php

use App\Modules\Academic\Actions\CreateDepartment;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Rules\Constraints\CatalogsValidated;
use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Actions\ReopenReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\CatalogValidationPermission;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\CatalogContentsQuery;
use App\Modules\CatalogValidation\Queries\CatalogReviewQuery;
use App\Modules\CatalogValidation\Queries\ReviewOwner;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermOpening;
use App\Modules\Core\Models\User;
use App\Modules\Core\Navigation\Navigation;
use App\Modules\Core\Navigation\NavigationItem;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;

/*
| CV15 (confirmada el 20 de noviembre de 2026): materias, planes de estudio y carreras los valida Servicios Escolares,
| con su propio tablero: una revisión por catálogo y periodo para todo el Tec, con todos sus registros. El jefe de un
| departamento académico ya no las ve ni las valida; sigue validando sus docentes y sus salones, una revisión por
| departamento. La carga de cualquier departamento no sale de borrador mientras las de Servicios Escolares abiertas no
| estén validadas (HC-14). Subdirección y el admin ven y resuelven observaciones (CV-S1).
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['code' => '20271', 'name' => 'Ene-Jun 2027', 'starts_on' => '2027-01-11', 'ends_on' => '2027-06-30']);
    Campus::factory()->create(['code' => 'C2']);
    TermOpening::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('PRES')->id]);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica-Electrónica']);

    $this->admin = User::factory()->admin()->create(['username' => 'admin']);
    $this->escolares = schoolServicesHead(['name' => 'Sara Escolar']);
    $this->jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Juan Pérez']);
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion)->create();

    $this->sisSubject = Subject::factory()->create(['code' => 'SCD-1027', 'department_id' => $this->sis->id]);
    $this->eleSubject = Subject::factory()->create(['code' => 'AEF-1009', 'department_id' => $this->ele->id]);
});

/**
 * Abre la validación de `$catalogs` (todos, sin decir) como el admin (CV9).
 *
 * @param  list<Catalog>|null  $catalogs
 */
function schoolServicesOpen(?array $catalogs = null): int
{
    return app(OpenCatalogValidation::class)->handle(test()->term, $catalogs ?? Catalog::cases(), test()->admin);
}

/**
 * La revisión del periodo de un catálogo: la de Servicios Escolares, o la del departamento en docentes y salones.
 */
function schoolServicesReview(Catalog $catalog, ?Department $department = null): CatalogReview
{
    return CatalogReview::query()
        ->where('term_id', test()->term->id)
        ->where('catalog', $catalog)
        ->tap(fn ($query) => CatalogReviewQuery::ownedBy($query, $department?->id))
        ->sole();
}

function schoolServicesPage(User $user, array $query = [])
{
    return Livewire::withQueryParams($query)->actingAs($user)->test('catalog-validation::pages.review');
}

describe('CV15: abrir', function () {
    it('CV15: crea una revisión de Servicios Escolares por catálogo para todo el Tec y una por departamento de docentes y salones', function () {
        expect(schoolServicesOpen())->toBe(7);

        foreach ([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers] as $catalog) {
            expect(CatalogReview::query()->where('catalog', $catalog)->pluck('department_id')->all())->toBe([null]);
        }

        foreach ([Catalog::Teachers, Catalog::Rooms] as $catalog) {
            expect(CatalogReview::query()->where('catalog', $catalog)->pluck('department_id')->sort()->values()->all())
                ->toBe(collect([$this->sis->id, $this->ele->id])->sort()->values()->all());
        }
    });

    it('CV15: abrir otra vez no repite la de Servicios Escolares; un departamento nuevo recibe solo docentes y salones (CV-S12)', function () {
        schoolServicesOpen();

        expect(schoolServicesOpen())->toBe(0);

        $new = app(CreateDepartment::class)->handle(['code' => 'IND', 'name' => 'Industrial']);

        expect(CatalogReview::query()->where('department_id', $new->id)->pluck('catalog')->map->value->sort()->values()->all())->toBe(['rooms', 'teachers'])
            ->and(CatalogReview::query()->whereNull('department_id')->count())->toBe(3);
    });

    it('CV15: la revisión de Servicios Escolares trae los registros de todo el Tec, de cualquier departamento', function () {
        schoolServicesOpen([Catalog::Subjects, Catalog::Careers, Catalog::StudyPlans]);
        $career = Career::factory()->create(['department_id' => $this->ele->id]);
        StudyPlan::factory()->for($career)->create(['code' => 'IELE-2010-200']);

        $contents = app(CatalogContentsQuery::class);

        expect(array_column($contents->records(schoolServicesReview(Catalog::Subjects)), 'code'))->toBe(['AEF-1009', 'SCD-1027'])
            ->and(array_column($contents->records(schoolServicesReview(Catalog::StudyPlans)), 'code'))->toBe(['IELE-2010-200'])
            ->and($contents->records(schoolServicesReview(Catalog::Careers)))->toHaveCount(1);
    });

    it('CV15: sin jefe de Servicios Escolares no se abren materias, planes ni carreras; docentes y salones sí (supuesto por confirmar)', function () {
        vacate(Role::JefeDepartamento, schoolServicesUnit()->id);

        Livewire::actingAs($this->admin)->test('catalog-validation::pages.progress')
            ->call('startOpening')
            ->assertSeeHtml('data-testid="open-blocked-subjects"')
            ->assertSee('Nombra al jefe de Servicios Escolares antes de abrir materias, planes y carreras')
            ->assertDontSeeHtml('data-testid="open-blocked-teachers"')
            ->set('toOpen', ['subjects'])
            ->call('openValidation')
            ->assertSet('showOpen', false);

        expect(CatalogReview::count())->toBe(0)
            ->and(schoolServicesOpen())->toBe(4)
            ->and(CatalogReview::query()->whereNull('department_id')->exists())->toBeFalse();
    });

    it('HC-14: sin jefe de Servicios Escolares sus catálogos no se abren y no detienen la carga; abiertos antes, sí', function () {
        vacate(Role::JefeDepartamento, schoolServicesUnit()->id);
        schoolServicesOpen();

        expect(app(CatalogReviewQuery::class)->pendingFor($this->term, modalityOf('PRES'), $this->sis))->toBe([Catalog::Teachers, Catalog::Rooms]);

        CatalogReview::factory()->general(Catalog::Careers)->create(['term_id' => $this->term->id]);

        expect(app(CatalogReviewQuery::class)->pendingFor($this->term, modalityOf('PRES'), $this->sis))->toBe([Catalog::Teachers, Catalog::Rooms, Catalog::Careers])
            ->and(app(ValidationProgressQuery::class)->board($this->term)['rows'][0]['remind_blocked_reason'])->toBe('No tiene jefe vigente.');
    });
});

describe('CV15: quién ve y quién actúa', function () {
    beforeEach(fn () => schoolServicesOpen());

    it('CV15: el jefe de Servicios Escolares hace todo en sus revisiones y nada en las de docentes y salones', function () {
        $abilities = ['view', 'correct', 'validate', 'reopen', 'flag', 'resolveFlag', 'mark'];
        $can = fn (User $user, CatalogReview $review): array => collect($abilities)->mapWithKeys(fn (string $ability) => [$ability => $user->can($ability, $review)])->all();

        expect(array_values(array_unique($can($this->escolares, schoolServicesReview(Catalog::Subjects)))))->toBe([true])
            ->and(array_values(array_unique($can($this->escolares, schoolServicesReview(Catalog::Teachers, $this->sis)))))->toBe([false])
            ->and(array_values(array_unique($can($this->escolares, schoolServicesReview(Catalog::Rooms, $this->sis)))))->toBe([false]);
    });

    it('CV15: el jefe de un departamento académico ya no ve ni corrige materias, planes ni carreras; sí sus docentes y salones', function (Catalog $catalog) {
        foreach (['view', 'correct', 'validate', 'reopen', 'flag', 'resolveFlag', 'mark'] as $ability) {
            expect($this->jefe->can($ability, schoolServicesReview($catalog)))->toBeFalse();
        }

        expect($this->jefe->can('validate', schoolServicesReview(Catalog::Teachers, $this->sis)))->toBeTrue()
            ->and($this->jefe->can('validate', schoolServicesReview(Catalog::Rooms, $this->sis)))->toBeTrue();
    })->with([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);

    it('CV-S1: Subdirección y el admin ven la revisión de Servicios Escolares y resuelven sus observaciones, nada más', function (string $who) {
        $user = $who === 'admin' ? $this->admin : $this->subdireccion;
        $review = schoolServicesReview(Catalog::Subjects);

        expect($user->can('view', $review))->toBeTrue()
            ->and($user->can('resolveFlag', $review))->toBeTrue()
            ->and($user->can('correct', $review))->toBeFalse()
            ->and($user->can('validate', $review))->toBeFalse()
            ->and($user->can('reopen', $review))->toBeFalse()
            ->and($user->can('flag', $review))->toBeFalse();
    })->with(['admin', 'subdirección']);

    it('AZ-02: el jefe de Servicios Escolares recibe solo los permisos de sus revisiones, ningún otro académico', function () {
        $this->actingAs($this->escolares);
        $context = app(Actor::class);

        expect($context->allowsAny(CatalogValidationPermission::ReviewValidate))->toBeTrue()
            ->and($context->allowsAny(CatalogValidationPermission::ProgressView))->toBeFalse()
            ->and($context->allowsAny(CatalogValidationPermission::ReviewOpen))->toBeFalse()
            ->and(CatalogReviewQuery::allowsOn($context, CatalogValidationPermission::ReviewView, $this->sis->id))->toBeFalse();
    });

    it('CV-S1: la Subdirección Académica no valida ni corrige lo de Servicios Escolares aunque el panel le dé esos permisos (R-V2-5)', function () {
        PermissionSet::factory()->withPermissions([CatalogValidationPermission::ReviewValidate, CatalogValidationPermission::ReviewCorrect])->assignedTo(Role::Subdireccion)->create();
        app()->forgetScopedInstances();
        $review = schoolServicesReview(Catalog::Subjects);

        expect($this->subdireccion->can('validate', schoolServicesReview(Catalog::Teachers, $this->sis)))->toBeTrue()
            ->and($this->subdireccion->can('validate', $review))->toBeFalse()
            ->and($this->subdireccion->can('correct', $review))->toBeFalse()
            ->and($this->subdireccion->can('view', $review))->toBeTrue()
            ->and(collect(app(CatalogReviewQuery::class)->holdersOf(ReviewOwner::SCHOOL_SERVICES, CatalogValidationPermission::ReviewValidate))->pluck('user.id')->all())->toBe([$this->escolares->id]);
    });

    it('CV15: el jefe de una unidad que contiene a Servicios Escolares no la ve ni la valida, ni recibe sus avisos', function () {
        $unit = OrgUnit::factory()->create(['code' => 'VINCULACION', 'name' => 'Departamento de Gestión Tecnológica y Vinculación', 'kind' => OrgUnitKind::Departamento, 'parent_id' => schoolServicesUnit()->parent_id]);
        schoolServicesUnit()->update(['parent_id' => $unit->id]);
        $boss = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $unit->id])->create();
        app()->forgetScopedInstances();
        $review = schoolServicesReview(Catalog::Subjects);

        foreach (['view', 'correct', 'validate', 'reopen', 'flag', 'resolveFlag', 'mark'] as $ability) {
            expect($boss->can($ability, $review))->toBeFalse();
        }

        expect($this->escolares->can('validate', $review))->toBeTrue()
            ->and(collect(app(CatalogReviewQuery::class)->holdersOf(ReviewOwner::SCHOOL_SERVICES, CatalogValidationPermission::ReviewValidate))->pluck('user.id')->all())->toBe([$this->escolares->id]);
    });

    it('CV15: el jefe de una unidad que no es académica ni Servicios Escolares no la ve ni la valida', function () {
        $finance = OrgUnit::factory()->create(['code' => 'RECURSOS-FINANCIEROS', 'name' => 'Departamento de Recursos Financieros', 'kind' => OrgUnitKind::Departamento, 'parent_id' => schoolServicesUnit()->parent_id]);
        $boss = User::factory()->withRole(Role::JefeDepartamento, ['org_unit_id' => $finance->id])->create();
        $review = schoolServicesReview(Catalog::Subjects);

        expect($boss->can('view', $review))->toBeFalse()
            ->and($boss->can('validate', $review))->toBeFalse();
        schoolServicesPage($boss, ['department' => ReviewOwner::SCHOOL_SERVICES])->assertForbidden();
    });

    it('AZ-03: un directivo de Planeación ve lo de Servicios Escolares pero no lo valida ni corrige, aunque el panel le diera esos permisos', function () {
        $directivo = User::factory()->withRole(Role::Directivo, ['org_unit_id' => schoolServicesUnit()->parent_id])->create();
        PermissionSet::factory()->withPermissions([CatalogValidationPermission::ReviewValidate, CatalogValidationPermission::ReviewCorrect])->assignedTo(Role::Directivo)->create();
        app()->forgetScopedInstances();
        $review = schoolServicesReview(Catalog::Subjects);

        expect($directivo->can('view', $review))->toBeTrue()
            ->and($directivo->can('validate', $review))->toBeFalse()
            ->and($directivo->can('correct', $review))->toBeFalse();
    });

    it('CV15: el menú le muestra «Validar catálogos» al jefe de Servicios Escolares', function () {
        $this->actingAs($this->escolares);

        expect(array_map(fn (NavigationItem $item): string => $item->label, app(Navigation::class)->visibleTo(app(Actor::class))))
            ->toContain('Validar catálogos')
            ->not->toContain('Avance de validación');
    });
});

describe('CV15: la pantalla', function () {
    beforeEach(fn () => schoolServicesOpen());

    it('CV15: el jefe de Servicios Escolares abre su tablero con materias, planes y carreras de todo el Tec', function () {
        schoolServicesPage($this->escolares)
            ->assertOk()
            ->assertSet('department', ReviewOwner::SCHOOL_SERVICES)
            ->assertSeeHtml('data-testid="tab-subjects"')
            ->assertSeeHtml('data-testid="tab-study_plans"')
            ->assertSeeHtml('data-testid="tab-careers"')
            ->assertDontSeeHtml('data-testid="tab-teachers"')
            ->assertDontSeeHtml('data-testid="tab-rooms"')
            ->assertSee('Servicios Escolares');
    });

    it('CV15: la lista de Servicios Escolares trae los registros de todo el Tec y los filtra por departamento', function () {
        schoolServicesPage($this->escolares, ['catalogo' => 'subjects'])
            ->set('segment', 'todos')
            ->assertViewHas('page', fn ($page): bool => $page->getCollection()->pluck('code')->all() === ['AEF-1009', 'SCD-1027'])
            ->assertViewHas('departmentFilters', fn (array $options): bool => array_column($options, 'id') === [$this->ele->id, $this->sis->id])
            ->set('recordDepartment', $this->sis->id)
            ->assertViewHas('page', fn ($page): bool => $page->getCollection()->pluck('code')->all() === ['SCD-1027'])
            ->assertSeeHtml('data-testid="filter-department"');
    });

    it('CV15: el jefe de Servicios Escolares no abre un departamento', function () {
        schoolServicesPage($this->escolares, ['department' => $this->sis->id])->assertForbidden();
    });

    it('CV15: el jefe académico solo ve docentes y salones de su departamento', function () {
        schoolServicesPage($this->jefe)
            ->assertOk()
            ->assertSeeHtml('data-testid="tab-teachers"')
            ->assertSeeHtml('data-testid="tab-rooms"')
            ->assertDontSeeHtml('data-testid="tab-subjects"')
            ->assertDontSeeHtml('data-testid="tab-study_plans"')
            ->assertDontSeeHtml('data-testid="tab-careers"');
    });

    it('CV15: el jefe académico no abre Servicios Escolares por la URL ni sus catálogos en su departamento', function () {
        schoolServicesPage($this->jefe, ['department' => ReviewOwner::SCHOOL_SERVICES])->assertForbidden();
        schoolServicesPage($this->jefe, ['department' => $this->sis->id, 'catalogo' => 'subjects'])
            ->assertOk()
            ->assertViewHas('current', null)
            ->assertDontSeeHtml('data-testid="tab-subjects"');
    });

    it('CV15: el jefe académico no valida las materias aunque llame con la página que abrió Servicios Escolares', function (string $method, array $arguments) {
        $page = schoolServicesPage($this->escolares, ['catalogo' => 'subjects']);

        $this->actingAs($this->jefe);
        $page->call($method, ...$arguments)->assertForbidden();

        expect(schoolServicesReview(Catalog::Subjects)->status)->toBe(ReviewStatus::Pending);
    })->with([
        'validar' => ['confirm', ['validate', 'subjects']],
        'marcar' => ['setReviewed', [fakeKey(1), true]],
        'editar' => ['edit', [fakeKey(1)]],
    ]);

    it('CV-S1: Subdirección elige Servicios Escolares en el selector y lo ve en lectura', function () {
        schoolServicesPage($this->subdireccion)
            ->assertViewHas('departments', fn ($owners): bool => $owners->pluck('id')->all() === [ReviewOwner::SCHOOL_SERVICES, $this->ele->id, $this->sis->id]);

        schoolServicesPage($this->subdireccion, ['department' => ReviewOwner::SCHOOL_SERVICES])
            ->assertOk()
            ->assertViewHas('viewOnly', true)
            ->assertSeeHtml('data-testid="tab-subjects"');
    });
});

describe('CV15: validar y reabrir', function () {
    beforeEach(fn () => schoolServicesOpen());

    it('CV15: el jefe de Servicios Escolares valida las materias con la foto de todas las del Tec', function () {
        markAllReviewed(schoolServicesReview(Catalog::Subjects), $this->escolares);

        schoolServicesPage($this->escolares)
            ->call('confirm', 'validate', 'subjects')
            ->call('confirmed')
            ->assertHasNoErrors();

        $review = schoolServicesReview(Catalog::Subjects);
        expect($review->status)->toBe(ReviewStatus::Validated)
            ->and($review->validated_by)->toBe($this->escolares->id)
            ->and(array_column($review->snapshot, 'code'))->toBe(['AEF-1009', 'SCD-1027']);
    });

    it('CV3: las altas y bajas de Servicios Escolares se cuentan contra su última validación en el Tec', function () {
        $previous = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-18']);
        CatalogReview::factory()->general(Catalog::Subjects)->validated()->create(['term_id' => $previous->id, 'snapshot' => [['id' => $this->sisSubject->id, 'code' => 'SCD-1027', 'name' => null], ['id' => fakeKey(9), 'code' => 'OLD-1', 'name' => 'Vieja']]]);
        CatalogReview::factory()->general(Catalog::Teachers)->validated()->create(['term_id' => $previous->id, 'department_id' => $this->sis->id, 'snapshot' => []]);

        $summary = app(CatalogReviewQuery::class)->summary(schoolServicesReview(Catalog::Subjects));

        expect(array_column($summary['added'], 'code'))->toBe(['AEF-1009'])
            ->and(array_column($summary['removed'], 'code'))->toBe(['OLD-1']);
    });

    it('CV5: Servicios Escolares reabre mientras ninguna carga del periodo haya salido de borrador', function () {
        $review = validateAllReviewed(schoolServicesReview(Catalog::Careers), $this->escolares);
        AcademicLoad::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->ele->id, 'status' => LoadStatus::Draft]);

        expect(app(ReopenReview::class)->handle($review, $this->escolares)->status)->toBe(ReviewStatus::InReview);
    });

    it('CV5: la carga de cualquier departamento fuera de borrador veta reabrir la de Servicios Escolares (supuesto por confirmar)', function () {
        $review = validateAllReviewed(schoolServicesReview(Catalog::Careers), $this->escolares);
        AcademicLoad::factory()->create(['term_id' => $this->term->id, 'department_id' => $this->ele->id, 'status' => LoadStatus::Proposed]);

        expect(app(CatalogReviewQuery::class)->reopeningVetoes($review))->toBe(['La carga académica de ELE ya salió de borrador.'])
            ->and(fn () => app(ReopenReview::class)->handle($review, $this->escolares))->toThrow(ValidationException::class);
    });
});

describe('HC-14 con la revisión de Servicios Escolares', function () {
    beforeEach(function () {
        schoolServicesOpen();
        validateAllReviewed(schoolServicesReview(Catalog::Teachers, $this->sis), $this->jefe);
        validateAllReviewed(schoolServicesReview(Catalog::Rooms, $this->sis), $this->jefe);
    });

    it('HC-14: con docentes y salones validados, la carga del departamento sigue en borrador mientras Servicios Escolares no valide', function () {
        validateAllReviewed(schoolServicesReview(Catalog::Subjects), $this->escolares);

        expect(app(CatalogReviewQuery::class)->pendingFor($this->term, modalityOf('PRES'), $this->sis))->toBe([Catalog::StudyPlans, Catalog::Careers])
            ->and(app(CatalogsValidated::class)->reasonsFor($this->term, modalityOf('PRES'), $this->sis))->toBe(['HC-14: faltan por validar los catálogos de Planes de estudio y Carreras.']);
    });

    it('HC-14: con lo de Servicios Escolares validado, sale el departamento que validó lo suyo y no el que no', function () {
        foreach ([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers] as $catalog) {
            validateAllReviewed(schoolServicesReview($catalog), $this->escolares);
        }

        expect(app(CatalogReviewQuery::class)->pendingFor($this->term, modalityOf('PRES'), $this->sis))->toBe([])
            ->and(app(CatalogReviewQuery::class)->pendingFor($this->term, modalityOf('PRES'), $this->ele))->toBe([Catalog::Teachers, Catalog::Rooms]);
    });

    it('CV15: a cada quien le falta solo lo suyo', function () {
        $reviews = app(CatalogReviewQuery::class);

        expect($reviews->pendingOf($this->term, ReviewOwner::ofDepartment($this->sis)))->toBe([])
            ->and($reviews->pendingOf($this->term, ReviewOwner::ofDepartment($this->ele)))->toBe([Catalog::Teachers, Catalog::Rooms])
            ->and($reviews->pendingOf($this->term, ReviewOwner::schoolServices()))->toBe([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers]);
    });
});

describe('CV11: un aviso guardado en la cola antes de CV15', function () {
    it('el que trae el departamento en lugar del dueño sale igual', function () {
        $legacy = (new ReflectionClass(CatalogReviewsPending::class))->newInstanceWithoutConstructor();
        $key = fn (string $property): string => "\0".CatalogReviewsPending::class."\0{$property}";

        $legacy->__unserialize([
            $key('term') => $this->term,
            $key('department') => $this->sis,
            $key('pending') => [Catalog::Teachers],
            $key('deadline') => null,
            $key('reminder') => false,
            $key('progress') => [],
        ]);

        expect($legacy->message($this->jefe)->body)->toBe('Ene-Jun 2027 · Sistemas y Computación: docentes')
            ->and($legacy->message($this->jefe)->parameters)->toBe(['department' => $this->sis->id])
            ->and(unserialize(serialize(new CatalogReviewsPending($this->term, ReviewOwner::schoolServices(), [Catalog::Subjects])))->message($this->escolares)->body)
            ->toBe('Ene-Jun 2027 · Servicios Escolares: materias');
    });
});

describe('CV15: avance, Inicio y avisos', function () {
    beforeEach(fn () => schoolServicesOpen());

    it('CV15: el tablero de avance pone a Servicios Escolares primero, con sus tres catálogos, y a cada departamento con los suyos', function () {
        validateAllReviewed(schoolServicesReview(Catalog::Subjects), $this->escolares);

        $board = app(ValidationProgressQuery::class)->board($this->term);

        expect(array_column($board['columns'], 'key'))->toBe(['teachers', 'rooms', 'subjects', 'study_plans', 'careers'])
            ->and(array_map(fn (array $row): string => $row['department']->id, $board['rows']))->toBe([ReviewOwner::SCHOOL_SERVICES, $this->ele->id, $this->sis->id])
            ->and(array_keys($board['rows'][0]['cells']))->toBe(['subjects', 'study_plans', 'careers'])
            ->and(array_keys($board['rows'][1]['cells']))->toBe(['teachers', 'rooms'])
            ->and($board['rows'][0]['validated'])->toBe(1)
            ->and($board['rows'][0]['total'])->toBe(3)
            ->and($board['rows'][0]['heads']->pluck('name')->all())->toBe(['Sara Escolar'])
            ->and($board['totals']['subjects'])->toBe(['validated' => 1, 'in_review' => 0, 'total' => 1])
            ->and($board['totals']['teachers']['total'])->toBe(2)
            ->and($board['global']['total'])->toBe(7);
    });

    it('CV15: sin jefe de Servicios Escolares su renglón lo dice y no se le recuerda', function () {
        vacate(Role::JefeDepartamento, schoolServicesUnit()->id);

        $row = app(ValidationProgressQuery::class)->board($this->term)['rows'][0];

        expect($row['heads'])->toBeEmpty()
            ->and($row['remind_blocked_reason'])->toBe('No tiene jefe vigente.');
    });

    it('CV-S13: Inicio le dice al jefe de Servicios Escolares sus catálogos por validar, y al jefe académico solo los suyos', function () {
        actingAsRole($this->escolares, Role::JefeDepartamento);
        Livewire::test('core::pages.home')->assertSee('Tienes 3 catálogos por validar');

        actingAsRole($this->jefe, Role::JefeDepartamento);
        Livewire::test('core::pages.home')->assertSee('Tienes 2 catálogos por validar');
    });

    it('CV-S13: a Subdirección le cuenta a Servicios Escolares como un departamento más', function () {
        foreach ([Catalog::Subjects, Catalog::StudyPlans, Catalog::Careers] as $catalog) {
            validateAllReviewed(schoolServicesReview($catalog), $this->escolares);
        }

        expect(app(ValidationProgressQuery::class)->departmentsValidated($this->term))->toBe(['validated' => 1, 'total' => 3]);

        actingAsRole($this->subdireccion, Role::Subdireccion);
        Livewire::test('core::pages.home')->assertSee('1 de 3 departamentos validados');
    });

    it('CV11: el aviso de apertura le llega al jefe de Servicios Escolares con sus catálogos y al académico sin ellos', function () {
        Notification::fake();

        app(NotifyPendingReviews::class)->notifyOpened($this->term, Catalog::cases());

        Notification::assertSentTo($this->escolares, CatalogReviewsPending::class, function (CatalogReviewsPending $notice): bool {
            $message = $notice->message($this->escolares);

            return $message->body === 'Ene-Jun 2027 · Servicios Escolares: materias, planes de estudio y carreras'
                && $message->parameters === ['department' => ReviewOwner::SCHOOL_SERVICES];
        });
        Notification::assertSentTo($this->jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notice): bool => $notice->message($this->jefe)->body === 'Ene-Jun 2027 · Sistemas y Computación: docentes y salones');
    });

    it('CV11: el recordatorio a Servicios Escolares deja su bitácora y el tablero lo muestra como su último recordatorio', function () {
        Notification::fake();

        expect(app(RemindPendingReviews::class)->handle($this->term, $this->admin, [ReviewOwner::SCHOOL_SERVICES]))->toBe(1);

        Notification::assertSentTo($this->escolares, CatalogReviewsPending::class);
        Notification::assertNotSentTo($this->jefe, CatalogReviewsPending::class);
        expect(Activity::query()->where('event', 'reminded')->sole()->properties['owner'])->toBe(ReviewOwner::SCHOOL_SERVICES)
            ->and(app(ValidationProgressQuery::class)->lastReminders($this->term, [ReviewOwner::SCHOOL_SERVICES, $this->sis->id]))->toHaveKey(ReviewOwner::SCHOOL_SERVICES)->not->toHaveKey($this->sis->id);
    });
});
