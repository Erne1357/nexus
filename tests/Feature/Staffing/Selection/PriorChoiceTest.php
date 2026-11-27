<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherHourAllowance;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\RecordPriorChoice;
use App\Modules\Staffing\Actions\Selection\AdvanceSelections;
use App\Modules\Staffing\Actions\Selection\CancelSelection;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Actions\Selection\PublishSelection;
use App\Modules\Staffing\Actions\Selection\RecomputeSelectionOrder;
use App\Modules\Staffing\Actions\Selection\ReleaseSelectionGroup;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Jobs\InviteSelectionTeachersJob;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Notifications\SelectionCancelled;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
use App\Modules\Staffing\Queries\SelectionGroupsQuery;
use App\Modules\Staffing\Queries\SelectionOrderQuery;
use App\Modules\Staffing\Queries\SelectionTurnQuery;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Tests\Support\Staffing\SelectionWorld;

/*
| SEL-16 «Elección previa» (tarea 1.41, confirmada el 25 de noviembre de 2026): el docente que ya trae sus grupos
| escritos en la relación de materias no recibe turno: sale de la fila, nunca está en turno, el reloj lo ignora y, al
| publicar, su carga queda cerrada con sus horas y justificadas en HC-08. Nombres ficticios.
*/

function priorWorld(SelectionStatus $status = SelectionStatus::Draft): SelectionWorld
{
    return SelectionWorld::create($status);
}

/**
 * Registra la elección previa. Con la selección ya publicada o viva la Action la rechaza (SEL-16: solo en borrador), así que
 * para armar el escenario de una selección viva se registra como si siguiera en borrador y se restituye su estado.
 */
function markPrior(SelectionWorld $world, Teacher $teacher, array $groups = []): void
{
    $status = $world->selection->fresh()->status;

    if ($status !== SelectionStatus::Draft) {
        Selection::query()->whereKey($world->selection->getKey())->update(['status' => SelectionStatus::Draft]);
    }

    app(RecordPriorChoice::class)->handle($world->term, $world->selection->modality, $teacher, array_map(fn ($group): array => ['group' => $group], $groups), $world->admin);

    Selection::query()->whereKey($world->selection->getKey())->update(['status' => $status]);
    $world->selection->refresh();
}

it('SEL-16: la marca saca al docente de la fila: sin lugar, sin turno y con el estado de elección previa', function () {
    $world = priorWorld();
    $previo = $world->teacher(base: 20, position: 1, name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Waiting]);
    $otro = $world->teacher(base: 20, position: 2, name: 'Docente Otro');
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0), 'window_starts' => '09:00', 'window_ends' => '15:00', 'weekdays' => [1, 2, 3, 4, 5], 'slot_minutes' => 30]);
    markPrior($world, $previo, [$world->group('A')]);

    app(RecomputeSelectionOrder::class)->handle($world->selection->fresh());

    expect($world->entryOf($previo))
        ->status->toBe(SelectionEntryStatus::Done)
        ->prior_choice_at->not->toBeNull()
        ->position->toBe(0)
        ->slot_index->toBe(0)
        ->slot_starts_at->toBeNull()
        ->slot_ends_at->toBeNull()
        ->and($world->entryOf($otro))
        ->status->toBe(SelectionEntryStatus::Waiting)
        ->position->toBe(1)
        ->slot_starts_at->not->toBeNull();
});

it('SEL-16: con renglón del CSV conserva sus criterios pero el estado es elección previa', function () {
    $world = priorWorld();
    $previo = $world->teacher(base: 40, name: 'Docente Previo', entry: ['source' => SelectionEntrySource::Csv, 'csv_name' => 'Docente Previo', 'auto_eval' => false, 'failed_criteria' => 1, 'tier' => 1]);
    markPrior($world, $previo, [$world->group('A')]);

    app(RecomputeSelectionOrder::class)->handle($world->selection->fresh());

    expect($world->entryOf($previo))
        ->status->toBe(SelectionEntryStatus::Done)
        ->source->toBe(SelectionEntrySource::Csv)
        ->auto_eval->toBeFalse()
        ->failed_criteria->toBe(1)
        ->position->toBe(0);
});

it('SEL-16: si la marca se crea después de armar la selección, el siguiente recálculo la aplica', function () {
    $world = priorWorld();
    $docente = $world->teacher(name: 'Docente Tardío');
    app(RecomputeSelectionOrder::class)->handle($world->selection->fresh());
    expect($world->entryOf($docente)->status)->toBe(SelectionEntryStatus::Waiting);

    markPrior($world, $docente, [$world->group('A')]);
    app(RecomputeSelectionOrder::class)->handle($world->selection->fresh());

    expect($world->entryOf($docente))->status->toBe(SelectionEntryStatus::Done)->prior_choice_at->not->toBeNull();
});

it('SEL-16: la marca de otra modalidad no saca al docente de esta selección', function () {
    $world = priorWorld();
    $docente = $world->teacher(name: 'Docente Otra Modalidad');
    $other = Modality::factory()->create();
    app(RecordPriorChoice::class)->handle($world->term, $other, $docente, [], $world->admin);

    app(RecomputeSelectionOrder::class)->handle($world->selection->fresh());

    expect($world->entryOf($docente))->status->toBe(SelectionEntryStatus::Waiting)->prior_choice_at->toBeNull();
});

it('SEL-16: nunca está en turno y no toma, suelta ni termina su carga', function () {
    $world = priorWorld(SelectionStatus::Running);
    $previo = $world->teacher(base: 5, name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $propio = $world->group('A', [[1, '09:00', '10:00']]);
    $libre = $world->group('B', [[2, '09:00', '10:00']]);
    markPrior($world, $previo, [$propio]);

    expect(app(SelectionTurnQuery::class)->isTurnOf($world->selection, $previo, CarbonImmutable::now()))->toBeFalse();

    foreach ([
        fn () => app(PickSelectionGroup::class)->handle($world->selection, $previo, $libre, $world->room('A1'), $previo->user),
        fn () => app(ReleaseSelectionGroup::class)->handle($world->selection, $previo, $propio, $previo->user),
        fn () => app(FinishMyLoad::class)->handle($world->selection, $previo, true, $previo->user),
    ] as $attempt) {
        expect($attempt)->toThrow(ValidationException::class, 'SEL-16');
    }

    expect($propio->fresh()->teacher_id)->toBe($previo->id)
        ->and($libre->fresh()->teacher_id)->toBeNull();
});

it('SEL-16: el reloj no lo mueve a pendiente de ronda extra ni a sin resolver', function () {
    $world = priorWorld(SelectionStatus::Running);
    $previo = $world->teacher(name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $espera = $world->teacher(name: 'Docente Espera', position: 1, slotStartsAt: CarbonImmutable::now()->subHour());
    $world->selection->update(['first_round_ends_at' => CarbonImmutable::now()->subMinutes(30)]);

    app(AdvanceSelections::class)->handle(CarbonImmutable::now());

    expect($world->entryOf($previo)->status)->toBe(SelectionEntryStatus::Done)
        ->and($world->entryOf($espera)->status)->toBe(SelectionEntryStatus::PendingExtra);

    // Terminada la ronda extra tampoco lo toca.
    $world->selection->refresh()->update(['extra_round_ends_at' => CarbonImmutable::now()->subMinute()]);
    $this->travel(2)->days();
    app(AdvanceSelections::class)->handle(CarbonImmutable::now());

    expect($world->entryOf($previo)->status)->toBe(SelectionEntryStatus::Done)
        ->and($world->selection->fresh()->status)->toBe(SelectionStatus::Finished);
});

it('SEL-16: al publicar su renglón queda cerrado con las horas de sus grupos y su HC-08 justificado como en SEL-12', function () {
    Notification::fake();
    Bus::fake([InviteSelectionTeachersJob::class]);
    $world = priorWorld();
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0), 'window_starts' => '09:00', 'window_ends' => '15:00', 'weekdays' => [1, 2, 3, 4, 5], 'slot_minutes' => 30]);
    $fuera = $world->teacher(base: 20, name: 'Docente Fuera');
    $dentro = $world->teacher(base: 5, name: 'Docente Dentro', position: 2);
    markPrior($world, $fuera, [$world->group('A', [[1, '09:00', '10:00']])]);
    markPrior($world, $dentro, [$world->group('B', [[2, '09:00', '10:00']])]);

    app(PublishSelection::class)->handle($world->selection->fresh(), $world->admin);

    expect($world->entryOf($fuera))
        ->status->toBe(SelectionEntryStatus::Done)
        ->closed_at->not->toBeNull()
        ->closed_hours->toBe(5)
        ->and($world->entryOf($dentro)->closed_hours)->toBe(5)
        ->and(TeacherHourAllowance::query()->where('teacher_id', $fuera->id)->sole())
        ->hours->toBe(5)
        ->reason->toContain('Elección previa')
        ->and(TeacherHourAllowance::query()->where('teacher_id', $dentro->id)->sole()->hours)->toBe(5);

    // Ninguno tiene turno: ni aviso de turno, y la invitación de acceso sí sale.
    Notification::assertNotSentTo([$fuera->user, $dentro->user], SelectionTurnPublished::class);
    Bus::assertDispatched(InviteSelectionTeachersJob::class);
});

it('SEL-16: si todos los de la cola son elección previa, la selección no tiene turnos: se publica ya terminada, sin pedir hora de inicio', function () {
    Notification::fake();
    $world = priorWorld();
    $world->selection->update(['starts_at' => null]);
    $previo = $world->teacher(name: 'Docente Previo');
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);

    $published = app(PublishSelection::class)->handle($world->selection->fresh(), $world->admin);

    expect($published)
        ->status->toBe(SelectionStatus::Finished)
        ->published_at->not->toBeNull()
        ->finished_at->not->toBeNull()
        ->and($world->entryOf($previo))->closed_at->not->toBeNull()->closed_hours->toBe(5)
        ->and(TeacherHourAllowance::query()->where('teacher_id', $previo->id)->sole())->hours->toBe(5)->reason->toContain('Elección previa')
        ->and($world->load->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and(app(SelectionGroupsQuery::class)->liveSelectionFor($world->load->fresh()))->toBeNull()
        ->and(app(SelectionGroupsQuery::class)->finishedSelectionFor($world->load->fresh())?->getKey())->toBe($world->selection->getKey());
    Notification::assertNotSentTo($previo->user, SelectionTurnPublished::class);
});

it('SEL-16: con alguien que sí tiene turno la selección queda publicada y solo él recibe el aviso de turno', function () {
    Notification::fake();
    Bus::fake([InviteSelectionTeachersJob::class]);
    $world = priorWorld();
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0), 'window_starts' => '09:00', 'window_ends' => '15:00', 'weekdays' => [1, 2, 3, 4, 5], 'slot_minutes' => 30]);
    $previo = $world->teacher(name: 'Docente Previo');
    $espera = $world->teacher(name: 'Docente Espera', position: 2);
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);

    $published = app(PublishSelection::class)->handle($world->selection->fresh(), $world->admin);

    expect($published->status)->toBe(SelectionStatus::Published);
    Notification::assertSentTo($espera->user, SelectionTurnPublished::class);
    Notification::assertNotSentTo($previo->user, SelectionTurnPublished::class);
    Bus::assertDispatched(InviteSelectionTeachersJob::class);
});

it('SEL-16: al cancelar una selección publicada los de elección previa no reciben el aviso', function () {
    Notification::fake();
    $world = priorWorld(SelectionStatus::Published);
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)]);
    $previo = $world->teacher(name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $espera = $world->teacher(name: 'Docente Espera', position: 1, slotStartsAt: CarbonImmutable::now()->addDays(3));

    app(CancelSelection::class)->handle($world->selection->fresh(), $world->admin);

    Notification::assertSentTo($espera->user, SelectionCancelled::class);
    Notification::assertNotSentTo($previo->user, SelectionCancelled::class);
});

it('SEL-16: durante la selección viva el jefe le asigna otro grupo con «Asignación docente»', function () {
    $world = priorWorld(SelectionStatus::Running);
    $previo = $world->teacher(name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);
    $mas = $world->group('B', [[2, '09:00', '10:00']]);

    $assignment = app(AssignTeacher::class)->handle($mas, $previo, $world->admin);

    expect($mas->fresh()->teacher_id)->toBe($previo->id)
        ->and($assignment->reason)->toBe(AssignTeacher::SELECTION_REASON)
        ->and($world->entryOf($previo)->status)->toBe(SelectionEntryStatus::Done);
});

it('SEL-16: la lista de la orden los marca aparte, sin turno ni empate, con las horas de sus grupos', function () {
    $world = priorWorld(SelectionStatus::Published);
    $previo = $world->teacher(name: 'Docente Previo', entry: ['status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_index' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    $espera = $world->teacher(name: 'Docente Espera', position: 1, slotStartsAt: CarbonImmutable::now()->addDay());
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);

    $rows = collect(app(SelectionOrderQuery::class)->forSelection($world->selection))->keyBy('name');

    expect($rows['Docente Previo'])
        ->prior->toBeTrue()
        ->hours->toBe(5)
        ->slot->toBeNull()
        ->tie->toBeFalse()
        ->in_turn->toBeFalse()
        ->and($rows['Docente Espera'])->prior->toBeFalse()->tie->toBeFalse();
});

it('SEL-16: una selección en borrador sin CSV con elección previa sigue bloqueada para publicar (la cola recalculada no sustituye al CSV)', function () {
    $world = priorWorld();
    $world->selection->entries()->delete();
    $world->selection->update(['starts_at' => CarbonImmutable::now()->addDays(3)->setTime(9, 0), 'window_starts' => '09:00', 'window_ends' => '15:00', 'weekdays' => [1, 2, 3, 4, 5], 'slot_minutes' => 30]);
    $previo = $world->teacher(name: 'Docente Previo', entry: ['source' => SelectionEntrySource::Appended]);
    $world->selection->entries()->delete();
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);

    expect($world->selection->entries()->where('source', SelectionEntrySource::Csv)->exists())->toBeFalse()
        ->and($world->selection->entries()->exists())->toBeTrue()
        ->and(fn () => app(PublishSelection::class)->handle($world->selection->fresh(), $world->admin))->toThrow(ValidationException::class, 'CSV');
});

it('SEL-16: la orden mide la base de Nexus de quien tiene elección previa sin venir en el CSV aunque el periodo venga cargado sin sus fechas', function () {
    $world = priorWorld(SelectionStatus::Published);
    $previo = $world->teacher(name: 'Docente Previo Fuera', entry: ['source' => SelectionEntrySource::Appended, 'status' => SelectionEntryStatus::Done, 'prior_choice_at' => now(), 'position' => 0, 'slot_index' => 0, 'slot_starts_at' => null, 'slot_ends_at' => null]);
    markPrior($world, $previo, [$world->group('A', [[1, '09:00', '10:00']])]);

    // Así la carga SelectionTimetableQuery para la pantalla «Oferta»: el periodo sin `starts_on`.
    $selection = Selection::query()->with('term:id,code,name')->findOrFail($world->selection->getKey());

    $rows = collect(app(SelectionOrderQuery::class)->forSelection($selection))->keyBy('name');

    expect($rows['Docente Previo Fuera'])->prior->toBeTrue()->hours->toBe(5);
});
