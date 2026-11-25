<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Faculty\Models\TeacherHourAllowance;
use App\Modules\Staffing\Actions\RecordPriorChoice;
use App\Modules\Staffing\Actions\Selection\ConfirmPriorChoiceHours;
use App\Modules\Staffing\Actions\Selection\PublishSelection;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Jobs\InviteSelectionTeachersJob;
use App\Modules\Staffing\Notifications\PriorChoiceHoursPending;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Livewire\Livewire;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-16 ampliada (tarea 1.45, 25 de noviembre de 2026): el docente con elección previa cuyas horas quedan arriba de la
| banda de su base confirma con la casilla de responsabilidad; hasta entonces su HC-08 no se justifica. Nombres ficticios.
| Cada grupo vale 5 h (3 de teoría y 2 de práctica): con base 2 la banda llega a 4 h, así que un grupo la pasa por 1 h.
*/

/**
 * Una selección en borrador con un docente «Docente Arriba» (base 2, un grupo de 5 h: arriba de la banda) y otro «Docente
 * Dentro» (base 5, un grupo de 5 h: dentro), los dos con elección previa y un tercero con turno. Se publica.
 */
function confirmWorld(bool $publish = true): array
{
    Notification::fake();
    Bus::fake([InviteSelectionTeachersJob::class]);
    $world = SelectionWorld::create(SelectionStatus::Draft);
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0), 'window_starts' => '09:00', 'window_ends' => '15:00', 'weekdays' => [1, 2, 3, 4, 5], 'slot_minutes' => 30]);
    $arriba = $world->teacher(base: 2, name: 'Docente Arriba');
    $dentro = $world->teacher(base: 5, name: 'Docente Dentro', position: 2);
    $espera = $world->teacher(base: 5, name: 'Docente Espera', position: 3);

    foreach ([[$arriba, 'A', 1], [$dentro, 'B', 2]] as [$teacher, $code, $day]) {
        app(RecordPriorChoice::class)->handle($world->term, $world->selection->modality, $teacher, [['group' => $world->group($code, [[$day, '09:00', '10:00']])]], $world->admin);
    }

    foreach ([$arriba, $dentro, $espera] as $teacher) {
        RoleAssignment::factory()->for($teacher->user)->create(['role' => Role::Docente]);
    }

    if ($publish) {
        app(PublishSelection::class)->handle($world->selection->fresh(), $world->admin);
    }

    return [$world, $arriba, $dentro, $espera];
}

it('SEL-16: al publicar, el previo arriba de su banda queda cerrado pero sin HC-08 autorizado y «falta confirmar»; el de dentro sí se autoriza', function () {
    [$world, $arriba, $dentro, $espera] = confirmWorld();

    expect($world->entryOf($arriba))->closed_at->not->toBeNull()->closed_hours->toBe(5)->out_of_band_acknowledged_at->toBeNull()
        ->and(TeacherHourAllowance::query()->where('teacher_id', $arriba->id)->exists())->toBeFalse()
        ->and(TeacherHourAllowance::query()->where('teacher_id', $dentro->id)->sole()->hours)->toBe(5);

    $order = collect(app(SelectionOrderQuery::class)->forSelection($world->selection->fresh()))->keyBy('teacher_id');
    expect($order[$arriba->id]['confirmation'])->toBe('pending')
        ->and($order[$dentro->id]['confirmation'])->toBeNull();
});

it('SEL-16: el aviso de confirmar sale una sola vez y solo a quien debe confirmar', function () {
    [, $arriba, $dentro, $espera] = confirmWorld();

    Notification::assertSentToTimes($arriba->user, PriorChoiceHoursPending::class, 1);
    Notification::assertNotSentTo([$dentro->user, $espera->user], PriorChoiceHoursPending::class);
    Notification::assertSentTo($arriba->user, PriorChoiceHoursPending::class, fn (PriorChoiceHoursPending $notice): bool => $notice->message($arriba->user)->route === 'staffing.offer');
});

it('SEL-16: el docente confirma con la casilla: se autoriza su HC-08 y queda en la bitácora', function () {
    [$world, $arriba] = confirmWorld();

    $entry = app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, true, $arriba->user);

    expect($entry->out_of_band_acknowledged_at)->not->toBeNull()
        ->and(TeacherHourAllowance::query()->where('teacher_id', $arriba->id)->sole())
        ->hours->toBe(5)
        ->reason->toContain('confirmada por el docente');
    $log = Activity::query()->where('event', 'prior_choice_hours_confirmed')->sole();
    expect($log->causer_id)->toBe($arriba->user->id)->and($log->description)->toContain('Docente Arriba');
});

it('SEL-16: sin la casilla no confirma y no se autoriza nada', function () {
    [$world, $arriba] = confirmWorld();

    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, false, $arriba->user))
        ->toThrow(ValidationException::class, 'casilla');
    expect($world->entryOf($arriba)->out_of_band_acknowledged_at)->toBeNull()
        ->and(TeacherHourAllowance::query()->where('teacher_id', $arriba->id)->exists())->toBeFalse();
});

it('SEL-16: solo confirma el propio docente; otro docente o usuario recibe 403', function () {
    [$world, $arriba, $dentro] = confirmWorld();

    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, true, $dentro->user))
        ->toThrow(AuthorizationException::class);
    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, true, $world->admin))
        ->toThrow(AuthorizationException::class);
    expect($world->entryOf($arriba)->out_of_band_acknowledged_at)->toBeNull();

    Livewire::actingAs($world->admin)->test('staffing::pages.offer')->set('priorAcknowledged', true)->call('confirmPrior')->assertForbidden();
});

it('SEL-16: otra selección donde no está en la cola no deja confirmar', function () {
    [, $arriba] = confirmWorld();
    $other = SelectionWorld::create(SelectionStatus::Published);

    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($other->selection, $arriba, true, $arriba->user))
        ->toThrow(ValidationException::class, 'cola');
});

it('SEL-16: con la selección en borrador todavía no hay nada que confirmar', function () {
    [$world, $arriba] = confirmWorld(publish: false);

    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, true, $arriba->user))
        ->toThrow(ValidationException::class, 'no está publicada');
});

it('SEL-16: quien está dentro de su banda no tiene nada que confirmar', function () {
    [$world, , $dentro] = confirmWorld();

    expect(fn () => app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $dentro, true, $dentro->user))
        ->toThrow(ValidationException::class, 'no pasan de tu banda');
});

it('SEL-16: confirmar dos veces es idempotente: una sola autorización y una sola bitácora', function () {
    [$world, $arriba] = confirmWorld();
    $action = app(ConfirmPriorChoiceHours::class);

    $first = $action->handle($world->selection->fresh(), $arriba, true, $arriba->user);
    $second = $action->handle($world->selection->fresh(), $arriba, true, $arriba->user);

    expect($second->out_of_band_acknowledged_at?->equalTo($first->out_of_band_acknowledged_at))->toBeTrue()
        ->and(TeacherHourAllowance::query()->where('teacher_id', $arriba->id)->count())->toBe(1)
        ->and(Activity::query()->where('event', 'prior_choice_hours_confirmed')->count())->toBe(1);
});

it('SEL-16: la pantalla del docente muestra el aviso con la casilla, confirma y luego dice cuándo confirmó', function () {
    [$world, $arriba, $dentro] = confirmWorld();

    $page = Livewire::actingAs($arriba->user)->test('staffing::pages.offer')
        ->assertSeeHtml('data-testid="prior-confirm"')
        ->assertSeeHtml('data-testid="prior-acknowledge"')
        ->assertSee('pasas tu margen por')
        ->call('confirmPrior')
        ->assertHasErrors('acknowledged');

    $page->set('priorAcknowledged', true)->call('confirmPrior')
        ->assertHasNoErrors()
        ->assertDontSeeHtml('data-testid="prior-confirm"')
        ->assertSeeHtml('data-testid="prior-confirmed"')
        ->assertSee('Confirmaste tus horas el');

    // Quien está dentro de su banda no ve ningún aviso.
    Livewire::actingAs($dentro->user)->test('staffing::pages.offer')
        ->assertDontSeeHtml('data-testid="prior-confirm"')
        ->assertDontSeeHtml('data-testid="prior-confirmed"');
});

it('SEL-16: el monitor del admin y la lista de la orden marcan «falta confirmar» y luego «confirmó»', function () {
    [$world, $arriba] = confirmWorld();
    $world->selection->update(['status' => SelectionStatus::Running, 'starts_at' => now()->subHour()]);

    Livewire::actingAs($world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $world->selection->id])
        ->assertSeeHtml('data-testid="monitor-prior-pending"')
        ->assertSee('Falta confirmar sus horas');

    app(ConfirmPriorChoiceHours::class)->handle($world->selection->fresh(), $arriba, true, $arriba->user);

    Livewire::actingAs($world->admin)->test('staffing::pages.selection-admin', ['seleccion' => $world->selection->id])
        ->assertSeeHtml('data-testid="monitor-prior-confirmed"')
        ->assertDontSeeHtml('data-testid="monitor-prior-pending"');

    $order = collect(app(SelectionOrderQuery::class)->forSelection($world->selection->fresh()))->keyBy('teacher_id');
    expect($order[$arriba->id]['confirmation'])->toBe('confirmed');
});
