<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Enums\ReviewStatus;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\CatalogValidation\Queries\ValidationProgressQuery;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/*
| Tarea 2.5: el recordatorio del tablero de avance (RemindPendingReviews) envuelve NotifyPendingReviews, avisa solo
| a los jefes con pendientes y deja bitácora de quién recordó, a quién y cuándo.
*/

beforeEach(function () {
    $this->term = Term::factory()->current()->create(['name' => 'Ene-Jun 2027']);
    $this->c2 = Campus::factory()->create(['code' => 'C2']);
    $this->c1 = Campus::factory()->create(['code' => 'C1']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas']);
    $this->ele = Department::factory()->create(['code' => 'ELE', 'name' => 'Eléctrica']);
    $this->jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->sis->id])->create(['name' => 'Ana Soto']);
    $this->jefeEle = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $this->ele->id])->create();
    $this->subdireccion = User::factory()->withRole(Role::Subdireccion, [])->create();

    foreach ([$this->c2, $this->c1] as $campus) {
        app(CreateTermReviews::class)->handle($this->term, $campus);
    }

    CatalogReview::query()->where('department_id', $this->ele->id)->update(['status' => ReviewStatus::Validated]);
});

it('avisa solo a los jefes con pendientes y deja bitácora de quién recordó a quién', function () {
    Notification::fake();

    $notified = app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2]), $this->subdireccion);

    expect($notified)->toBe(1);
    Notification::assertSentTo($this->jefeSis, CatalogReviewsPending::class);
    Notification::assertNotSentTo($this->jefeEle, CatalogReviewsPending::class);

    $log = Activity::query()->where('event', 'reminded')->sole();

    expect($log->subject->is($this->sis))->toBeTrue()
        ->and($log->causer->is($this->subdireccion))->toBeTrue()
        ->and($log->properties['recipients'])->toBe(['Ana Soto'])
        ->and($log->properties['campuses'])->toBe(['C2'])
        ->and($log->properties['term_id'])->toBe($this->term->id);
});

it('recuerda solo a un departamento y a nadie si no le falta nada', function () {
    Notification::fake();

    expect(app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2]), $this->subdireccion, [$this->ele->id]))->toBe(0);
    Notification::assertNothingSent();
    expect(Activity::query()->where('event', 'reminded')->count())->toBe(0);

    expect(app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2]), $this->subdireccion, [$this->sis->id]))->toBe(1);
});

it('avisa en cada campus que se le pasa y cuenta una vez al jefe', function () {
    Notification::fake();

    expect(app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2, $this->c1]), $this->subdireccion))->toBe(1);
    expect(Activity::query()->where('event', 'reminded')->sole()->properties['campuses'])->toBe(['C2', 'C1']);
});

it('el último recordatorio de cada departamento sale de la bitácora', function () {
    Notification::fake();
    $this->travelTo(now()->subDay());
    app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2]), $this->subdireccion);
    $this->travelBack();
    app(RemindPendingReviews::class)->handle($this->term, collect([$this->c2]), $this->subdireccion, [$this->sis->id]);

    $last = app(ValidationProgressQuery::class)->lastReminders($this->term, [$this->sis->id, $this->ele->id], ['C2']);

    expect($last)->toHaveKey($this->sis->id)->not->toHaveKey($this->ele->id)
        ->and($last[$this->sis->id]->isToday())->toBeTrue();
});
