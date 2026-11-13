<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\NotifyPendingReviews;
use App\Modules\CatalogValidation\Actions\OpenCatalogValidation;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;

/*
| Tarea 2.9: el aviso a los jefes de que tienen catálogos por validar (reglas-de-negocio.md, «Periodo y
| calendario»). Le llega al jefe de departamento, el único que valida, de cada departamento con revisiones
| pendientes del periodo en el campus, con la misma cuenta que HC-14 (CatalogReviewQuery::pendingFor). Lo
| manda NotifyPendingReviews al abrir el periodo y, a demanda, el recordatorio del tablero (tarea 2.5).
*/

beforeEach(function () {
    $this->travelTo(CarbonImmutable::parse('2026-11-02 12:00', 'America/Ciudad_Juarez'));

    $this->term = Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sistemas = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->basicas = Department::factory()->create(['code' => 'CB', 'name' => 'Ciencias Básicas']);

    $this->jefeSistemas = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sistemas->id])->create();
    $this->jefeBasicas = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->basicas->id])->create();
});

function reviewsFor(Term $term, Campus $campus, Department $department, ReviewStatus $status): void
{
    foreach (Catalog::cases() as $catalog) {
        CatalogReview::factory()->create([
            'term_id' => $term->id,
            'campus_id' => null,
            'department_id' => $department->id,
            'catalog' => $catalog,
            'status' => $status,
        ]);
    }
}

it('avisa al jefe de cada departamento con catálogos pendientes y dice cuáles', function () {
    Notification::fake();
    reviewsFor($this->term, $this->c2, $this->sistemas, ReviewStatus::Pending);
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Validated);

    $notified = app(NotifyPendingReviews::class)->handle($this->term, $this->c2);

    expect($notified)->toBe(1);
    Notification::assertSentTo($this->jefeSistemas, CatalogReviewsPending::class, function (CatalogReviewsPending $notification) {
        $message = $notification->message($this->jefeSistemas);

        return $message->title === 'Tienes catálogos por validar'
            && $message->body === 'Ene-Jun 2027 · Sistemas y Computación: docentes, materias, planes de estudio, carreras y salones'
            && $message->route === 'catalog-validation.review'
            && $message->parameters === ['department' => $this->sistemas->id]
            && $message->termId === $this->term->id
            && $message->campusId === $this->c2->id;
    });
    Notification::assertNotSentTo($this->jefeBasicas, CatalogReviewsPending::class);
});

it('MS34: el aviso cuenta lo que el admin abrió, sin un perfil de campus: con los salones validados, solo docentes', function () {
    Notification::fake();
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Validated);
    CatalogReview::query()->where('department_id', $this->basicas->id)->where('catalog', Catalog::Teachers)->update(['status' => ReviewStatus::InReview]);

    app(NotifyPendingReviews::class)->handle($this->term, $this->c2);

    Notification::assertSentTo($this->jefeBasicas, CatalogReviewsPending::class, fn (CatalogReviewsPending $notification) => $notification->message($this->jefeBasicas)->body === 'Ene-Jun 2027 · Ciencias Básicas: docentes');
});

it('con un departamento, solo avisa a su jefe (el recordatorio del tablero)', function () {
    Notification::fake();
    reviewsFor($this->term, $this->c2, $this->sistemas, ReviewStatus::Pending);
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Pending);

    expect(app(NotifyPendingReviews::class)->handle($this->term, $this->c2, $this->basicas->id))->toBe(1);

    Notification::assertSentTo($this->jefeBasicas, CatalogReviewsPending::class);
    Notification::assertNotSentTo($this->jefeSistemas, CatalogReviewsPending::class);
});

it('con un id que no es de un departamento activo, no avisa a nadie', function (string $id) {
    Notification::fake();
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Pending);

    expect(app(NotifyPendingReviews::class)->handle($this->term, $this->c2, $id))->toBe(0);

    Notification::assertNothingSent();
})->with(['no es uuid' => 'abc', 'no existe' => fn () => fakeKey(99)]);

it('no avisa a los departamentos inactivos ni a quien no es jefe de ese departamento', function () {
    Notification::fake();
    $this->sistemas->update(['is_active' => false]);
    reviewsFor($this->term, $this->c2, $this->sistemas, ReviewStatus::Pending);
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['department_id' => $this->basicas->id, 'campus_id' => $this->c2->id])->create();
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Pending);

    expect(app(NotifyPendingReviews::class)->handle($this->term, $this->c2))->toBe(1);

    Notification::assertSentTo($this->jefeBasicas, CatalogReviewsPending::class);
    Notification::assertNotSentTo([$this->jefeSistemas, $jpd], CatalogReviewsPending::class);
});

it('CV9: al abrir la validación avisa a los jefes y abrir el periodo no', function () {
    $admin = User::factory()->admin()->create();
    $this->actingAs($admin);

    app(OpenTerm::class)->handle($this->term, modalityOf('PRES'));

    expect($this->jefeSistemas->notifications()->count())->toBe(0);

    app(OpenCatalogValidation::class)->handle($this->term, Catalog::cases(), $admin);

    expect($this->jefeSistemas->notifications()->count())->toBe(1)
        ->and($this->jefeBasicas->notifications()->count())->toBe(1)
        ->and($this->jefeSistemas->notifications()->first()->data['title'])->toBe('Tienes catálogos por validar');
});

it('si la apertura falla, no avisa a nadie', function () {
    $this->actingAs(User::factory()->admin()->create());
    modalityOf('PRES')->update(['is_active' => false]);

    expect(fn () => app(OpenTerm::class)->handle($this->term, modalityOf('PRES')))->toThrow(ValidationException::class);

    expect(DatabaseNotification::query()->count())->toBe(0);
});

it('notify() da los usuarios avisados y handle() sigue dando cuántos', function () {
    Notification::fake();
    reviewsFor($this->term, $this->c2, $this->sistemas, ReviewStatus::Pending);
    reviewsFor($this->term, $this->c2, $this->basicas, ReviewStatus::Validated);

    $users = app(NotifyPendingReviews::class)->notify($this->term, $this->c2);

    expect($users->pluck('id')->all())->toBe([$this->jefeSistemas->id])
        ->and(app(NotifyPendingReviews::class)->handle($this->term, $this->c2))->toBe(1);
});

it('guarda el puesto de jefe en el aviso', function () {
    reviewsFor($this->term, $this->c2, $this->sistemas, ReviewStatus::Pending);

    app(NotifyPendingReviews::class)->handle($this->term, $this->c2);

    expect($this->jefeSistemas->notifications()->first()->data['role'])->toBe('jefe_departamento');
});
