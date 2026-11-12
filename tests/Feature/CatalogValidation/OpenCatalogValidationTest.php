<?php

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
use App\Modules\Core\Models\CampusProfile;
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
    app(OpenTerm::class)->handle($this->term, $this->c2);
});

function openValidation(array $catalogs, ?User $actor = null): int
{
    return app(OpenCatalogValidation::class)->handle(test()->term, $catalogs, $actor ?? test()->admin);
}

it('CV9: abrir el periodo ya no crea revisiones ni avisa a los jefes', function () {
    Queue::fake();
    $term = Term::factory()->create();

    app(OpenTerm::class)->handle($term, $this->c2);

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

it('CV9: los salones solo se abren si algún campus abierto en el periodo usa salones, una revisión por departamento', function () {
    $this->c2->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);

    expect(openValidation([Catalog::Rooms]))->toBe(0)
        ->and(CatalogReview::count())->toBe(0);

    $this->c2->update(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => true]]);
    $c1 = Campus::factory()->create(['code' => 'C1']);
    app(OpenTerm::class)->handle($this->term, $c1);

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
    $event = new TermOpened(Term::factory()->create(), $this->c2);
    event($event);

    expect(array_values(array_filter($event->lines(), fn (string $line): bool => str_contains($line, 'revisi'))))->toBe([]);
});
