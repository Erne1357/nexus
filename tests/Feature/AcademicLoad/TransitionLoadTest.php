<?php

use App\Modules\AcademicLoad\Actions\Transitions\TransitionLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| TransitionLoad es el único lugar que cambia `academic_loads.status`: valida el diagrama, junta los motivos
| de la guarda y del veto de LoadTransitioning, y escribe estado, historial (HC-18), bitácora y evento.
*/

function transition(AcademicLoad $load, LoadStatus $to, ?string $comment = null, ?Closure $guard = null, ?User $actor = null): AcademicLoad
{
    return app(TransitionLoad::class)->handle($load, $to, $actor ?? test()->user, $comment, $guard ?? fn (): array => []);
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('cambia el estado, guarda la transición con quién y el comentario, y deja bitácora', function () {
    $load = AcademicLoad::factory()->create();

    transition($load, LoadStatus::Proposed, '  Lista para asignar  ');

    expect($load->fresh()->status)->toBe(LoadStatus::Proposed)
        ->and(LoadTransition::query()->count())->toBe(1);

    $row = LoadTransition::query()->firstOrFail();
    expect($row->academic_load_id)->toBe($load->id)
        ->and($row->from_status)->toBe(LoadStatus::Draft)
        ->and($row->to_status)->toBe(LoadStatus::Proposed)
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->comment)->toBe('Lista para asignar');

    $activity = Activity::query()->where('event', 'transitioned')->firstOrFail();
    expect($activity->subject_id)->toBe($load->id)
        ->and($activity->causer_id)->toBe($this->user->id)
        ->and($activity->attribute_changes['old'])->toBe(['status' => 'draft'])
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'proposed'])
        ->and($activity->properties['comment'])->toBe('Lista para asignar')
        ->and($activity->description)->toBe("Pasó la carga de {$load->department->code} en {$load->modality->name} de Borrador a Propuesta");
});

it('guarda un comentario vacío como nulo', function () {
    transition(AcademicLoad::factory()->create(), LoadStatus::Proposed, '   ');

    expect(LoadTransition::query()->firstOrFail()->comment)->toBeNull();
});

it('despacha LoadTransitioned solo después del commit', function () {
    Event::fake([LoadTransitioned::class]);
    $load = AcademicLoad::factory()->create();

    transition($load, LoadStatus::Proposed);

    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->load->is($load)
        && $event->from === LoadStatus::Draft && $event->to === LoadStatus::Proposed);
});

it('no despacha LoadTransitioned si la transacción externa se revierte', function () {
    Event::fake([LoadTransitioned::class]);
    $load = AcademicLoad::factory()->create();

    try {
        DB::transaction(function () use ($load) {
            transition($load, LoadStatus::Proposed);

            throw new RuntimeException('revertir');
        });
    } catch (RuntimeException) {
    }

    Event::assertNotDispatched(LoadTransitioned::class);
    expect($load->fresh()->status)->toBe(LoadStatus::Draft);
});

it('rechaza una transición que el diagrama no permite y no escribe nada', function () {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create();

    expect(fn () => transition($load, LoadStatus::Proposed))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['La carga está en Publicada: no puede pasar a Propuesta.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Published)
        ->and(LoadTransition::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'transitioned')->count())->toBe(0);
});

it('rechaza con todos los motivos de la guarda y no escribe nada', function () {
    $load = AcademicLoad::factory()->create();

    expect(fn () => transition($load, LoadStatus::Proposed, null, fn (): array => ['Falta A.', 'Falta B.']))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['Falta A.', 'Falta B.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Draft)
        ->and(LoadTransition::query()->count())->toBe(0);
});

it('rechaza con el motivo de un listener de LoadTransitioning que bloquea, junto con los de la guarda', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('Hay grupos sin docente.'));
    $load = AcademicLoad::factory()->create();

    expect(fn () => transition($load, LoadStatus::Proposed, null, fn (): array => ['Falta A.']))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['Falta A.', 'Hay grupos sin docente.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Draft);
});

it('despacha LoadTransitioning con el estado de origen y el de destino', function () {
    $seen = null;
    Event::listen(LoadTransitioning::class, function (LoadTransitioning $event) use (&$seen) {
        $seen = [$event->from, $event->to];
    });

    transition(AcademicLoad::factory()->create(), LoadStatus::Proposed);

    expect($seen)->toBe([LoadStatus::Draft, LoadStatus::Proposed]);
});

it('usa el estado bloqueado de la base y no el del modelo desactualizado', function () {
    $load = AcademicLoad::factory()->create();
    DB::table('academic_loads')->where('id', $load->id)->update(['status' => 'published']);

    expect(fn () => transition($load, LoadStatus::Proposed))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['La carga está en Publicada: no puede pasar a Propuesta.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Published);
});

it('usa al actor recibido como causante y autor aunque nadie haya iniciado sesión', function () {
    auth()->logout();
    $actor = User::factory()->admin()->create();

    transition(AcademicLoad::factory()->create(), LoadStatus::Proposed, actor: $actor);

    expect(LoadTransition::query()->firstOrFail()->user_id)->toBe($actor->id)
        ->and(Activity::query()->where('event', 'transitioned')->firstOrFail()->causer_id)->toBe($actor->id);
});
