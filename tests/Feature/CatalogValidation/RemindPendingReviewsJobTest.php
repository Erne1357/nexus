<?php

use App\Modules\Academic\Models\Department;
use App\Modules\CatalogValidation\Actions\CreateTermReviews;
use App\Modules\CatalogValidation\Actions\RemindPendingReviews;
use App\Modules\CatalogValidation\Jobs\RemindPendingReviewsJob;
use App\Modules\CatalogValidation\Notifications\CatalogReviewsPending;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\Notification;
use Spatie\Activitylog\Models\Activity;

/*
| Tarea 2.5: «Recordar a todos los pendientes» va a la cola (CLAUDE.md §4.7) y recuerda solo a los departamentos
| que le pasa la pantalla.
*/

it('es un job en cola que avisa solo a los departamentos dados y deja bitácora de quien lo pidió', function () {
    Notification::fake();
    $term = Term::factory()->current()->create();
    $campus = Campus::factory()->create(['code' => 'C2']);
    $sis = Department::factory()->create();
    $ele = Department::factory()->create();
    app(CreateTermReviews::class)->handle($term, $campus->catalogModality);
    $jefeSis = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $sis->id])->create();
    $jefeEle = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $ele->id])->create();
    $actor = User::factory()->withRole(Role::Subdireccion, [])->create();

    $job = new RemindPendingReviewsJob($term->id, [$campus->id], $actor->id, [$sis->id]);
    $job->handle(app(RemindPendingReviews::class));

    expect($job)->toBeInstanceOf(ShouldQueue::class);
    Notification::assertSentTo($jefeSis, CatalogReviewsPending::class);
    Notification::assertNotSentTo($jefeEle, CatalogReviewsPending::class);
    expect(Activity::query()->where('event', 'reminded')->sole()->causer->is($actor))->toBeTrue();
});
