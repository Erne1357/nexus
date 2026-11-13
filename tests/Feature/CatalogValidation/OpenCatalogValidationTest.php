<?php

use App\Modules\Academic\Actions\CreateDepartment;
use App\Modules\Academic\Actions\UpdateDepartment;
use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Jobs\NotifyPendingReviewsJob;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Events\TermOpened;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Queue;
use Spatie\Activitylog\Models\Activity;

/*
| CV9 (confirmada el 12 de noviembre de 2026): la validación ya no nace al abrir el periodo; el admin decide por periodo
| qué catálogos se validan (ninguno, algunos o todos) y después puede abrir uno que faltó. OpenCatalogValidation crea
| las revisiones, deja bitácora y avisa a los jefes. Un catálogo abierto no se quita (supuesto por confirmar).
*/

beforeEach(function () {
    $this->admin = User::factory()->admin()->create();
    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->d1 = Department::factory()->create(['code' => 'D1']);
    Department::factory()->create(['code' => 'D2']);
    $this->actingAs($this->admin);
    app(OpenTerm::class)->handle($this->term, modalityOf('PRES'));
});

function openValidation(array $catalogs, ?User $actor = null): int
{
    return app(OpenCatalogValidation::class)->handle(test()->term, $catalogs, $actor ?? test()->admin);
}

it('CV9: abrir el periodo ya no crea revisiones ni avisa a los jefes', function () {
    Queue::fake();
    $term = Term::factory()->create();

    app(OpenTerm::class)->handle($term, modalityOf('PRES'));

    expect(CatalogReview::query()->where('term_id', $term->id)->count())->toBe(0)
        ->and(CatalogReview::query()->where('term_id', $this->term->id)->count())->toBe(0);
    Queue::assertNothingPushed();
});

it('CV9: abrir crea las revisiones solo de los catálogos elegidos en cada departamento activo', function () {
    Department::factory()->inactive()->create();

    expect(openValidation([Catalog::Teachers, Catalog::Careers]))->toBe(4)
        ->and(CatalogReview::query()->where('term_id', $this->term->id)->pluck('catalog')->unique()->map->value->sort()->values()->all())->toBe(['careers', 'teachers'])
        ->and(CatalogReview::query()->whereNotNull('campus_id')->count())->toBe(0);
});

it('CV9: es idempotente y se puede abrir después un catálogo que faltó, sin quitar los ya abiertos', function () {
    Queue::fake();
    openValidation([Catalog::Teachers]);

    expect(openValidation([Catalog::Teachers]))->toBe(0)
        ->and(openValidation([Catalog::Teachers, Catalog::Subjects]))->toBe(2)
        ->and(CatalogReview::count())->toBe(4);
    Queue::assertPushed(NotifyPendingReviewsJob::class, 2);
});

it('CV9: sin catálogos, o con todos ya abiertos, no hace nada: sin bitácora ni aviso', function () {
    Queue::fake();
    expect(openValidation([]))->toBe(0);
    openValidation([Catalog::Teachers]);
    Queue::fake();

    expect(openValidation([Catalog::Teachers]))->toBe(0);
    Queue::assertNothingPushed();
    expect(Activity::query()->where('event', 'validation_opened')->count())->toBe(1);
});

it('CV9: los salones solo se abren si alguna modalidad abierta en el periodo usa salones, una revisión por departamento', function () {
    modalityOf('PRES')->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_rooms' => false]]);

    expect(openValidation([Catalog::Rooms]))->toBe(0)
        ->and(CatalogReview::count())->toBe(0);

    modalityOf('PRES')->update(['settings' => ModalityProfile::DEFAULTS]);
    Campus::factory()->create(['code' => 'C1']);

    expect(openValidation([Catalog::Rooms]))->toBe(2)
        ->and(CatalogReview::query()->where('catalog', Catalog::Rooms->value)->count())->toBe(2);
});

it('CV9: deja una entrada en la bitácora del periodo con los catálogos y quién abrió', function () {
    openValidation([Catalog::Subjects, Catalog::Teachers]);

    $log = Activity::query()->where('event', 'validation_opened')->sole();

    expect($log->subject_id)->toBe($this->term->id)
        ->and($log->causer_id)->toBe($this->admin->id)
        ->and($log->properties['catalogs'])->toBe(['teachers', 'subjects'])
        ->and($log->properties['reviews'])->toBe(4)
        ->and($log->description)->toContain('docentes, materias');
});

it('CV9: avisa a los jefes solo de los catálogos que se abrieron', function () {
    Notification::fake();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->d1->id])->create();

    openValidation([Catalog::Careers]);

    Notification::assertSentTo($jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $notification) => $notification->message($jefe)->body === 'Ene-Jun 2027 · '.$this->d1->name.': carreras');
});

it('CV9: abrir la validación es solo del admin: Subdirección y el jefe no pueden', function (Role $role, array $scope) {
    $user = User::factory()->withRole($role, $scope)->create();

    expect(Gate::forUser($user)->allows('open', CatalogReview::class))->toBeFalse()
        ->and(Gate::forUser($this->admin)->allows('open', CatalogReview::class))->toBeTrue();
})->with([
    'subdirección' => [Role::Subdireccion, []],
    'jefe de departamento' => [Role::JefeDepartamento, fn () => ['department_id' => test()->d1->id]],
]);

it('CatalogValidation ya no escucha TermOpened', function () {
    expect(Event::hasListeners(TermOpened::class))->toBeTrue(); // AcademicLoad sigue escuchando
    $event = new TermOpened(Term::factory()->create(), modalityOf('PRES'));
    event($event);

    expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'revisi'))))->toBe([]);
});

it('CV9: la bitácora lista solo los catálogos que de verdad crearon revisiones', function () {
    openValidation([Catalog::Teachers]);
    openValidation([Catalog::Teachers, Catalog::Careers]);

    $logs = Activity::query()->where('event', 'validation_opened')->orderBy('created_at')->get();

    expect($logs)->toHaveCount(2)
        ->and($logs->last()->properties['catalogs'])->toBe(['careers'])
        ->and($logs->last()->description)->toContain('carreras')->not->toContain('docentes');
});

it('CV9: manda el aviso en cola después del commit, con los catálogos recién abiertos', function () {
    Queue::fake();

    openValidation([Catalog::Subjects]);

    Queue::assertPushed(NotifyPendingReviewsJob::class, fn (NotifyPendingReviewsJob $job) => $job->catalogs === ['subjects'] && $job->afterCommit === true);
});

it('CV9: un jefe de todo el Tec recibe un solo aviso aunque haya varios campus abiertos, con solo los catálogos recién abiertos', function () {
    Notification::fake();
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->d1->id])->create();
    // C1 es de Presencial, donde el periodo ya está abierto (M4): hay dos campus abiertos.
    Campus::factory()->create(['code' => 'C1']);
    openValidation([Catalog::Teachers]);
    Notification::fake();

    openValidation([Catalog::Careers, Catalog::Subjects]);

    Notification::assertSentToTimes($jefe, CatalogReviewsPending::class, 1);
    Notification::assertSentTo($jefe, CatalogReviewsPending::class, fn (CatalogReviewsPending $n) => str_ends_with($n->message($jefe)->body, ': materias y carreras'));
});

describe('CV-S12: un departamento nuevo o reactivado', function () {
    it('al darse de alta recibe las revisiones de los catálogos ya abiertos en el periodo abierto, y nada si no hay ninguno abierto', function () {
        app(CreateDepartment::class)->handle(['code' => 'D7', 'name' => 'Nuevo']);
        expect(CatalogReview::count())->toBe(0);

        openValidation([Catalog::Teachers, Catalog::Careers]);
        $new = app(CreateDepartment::class)->handle(['code' => 'D8', 'name' => 'Otro nuevo']);

        expect(CatalogReview::query()->where('department_id', $new->id)->pluck('catalog')->map->value->sort()->values()->all())->toBe(['careers', 'teachers']);
    });

    it('al reactivarse recibe las suyas; desactivar o cambiar el nombre no crea nada, y un periodo ya terminado no se toca', function () {
        $inactive = Department::factory()->inactive()->create(['code' => 'D9']);
        openValidation([Catalog::Teachers]);
        $past = Term::factory()->create(['code' => '20201', 'starts_on' => '2020-01-20', 'ends_on' => '2020-06-10']);
        app(OpenTerm::class)->handle($past, modalityOf('PRES'));
        CatalogReview::factory()->general(Catalog::Teachers)->create(['term_id' => $past->id, 'department_id' => $this->d1->id]);

        app(UpdateDepartment::class)->handle($inactive, ['name' => 'Renombrado']);
        expect(CatalogReview::query()->where('department_id', $inactive->id)->count())->toBe(0);

        app(UpdateDepartment::class)->handle($inactive, ['is_active' => true]);

        expect(CatalogReview::query()->where('department_id', $inactive->id)->where('term_id', $this->term->id)->count())->toBe(1)
            ->and(CatalogReview::query()->where('department_id', $inactive->id)->where('term_id', $past->id)->count())->toBe(0);

        app(UpdateDepartment::class)->handle($inactive, ['is_active' => false]);
        app(UpdateDepartment::class)->handle($inactive, ['is_active' => true]);

        expect(CatalogReview::query()->where('department_id', $inactive->id)->count())->toBe(1);
    });
});
