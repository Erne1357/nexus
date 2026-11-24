<?php

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Actions\Selection\AdvanceSelections;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Notifications\SelectionExtraRoundScheduled;
use App\Modules\Staffing\Notifications\SelectionFinishedWithPending;
use App\Modules\Staffing\Notifications\SelectionTurnStarting;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;

/*
| staffing:advance-selections (tarea 1.33, diseño 6.3, SEL-08 y SEL-09): el turno vigente sale de las horas; el comando
| solo hace los efectos (estados, avisos, pendientes, ronda extra, cierre) y es idempotente.
*/

function clockAt(string $time): CarbonImmutable
{
    return CarbonImmutable::parse("2027-01-04 {$time}", 'America/Ciudad_Juarez');
}

/**
 * Selección publicada (lunes 4 de enero de 2027) con un renglón por turno de 30 minutos desde las 09:00.
 *
 * @return array{0: Selection, 1: list<SelectionEntry>}
 */
function clockSelection(int $entries = 2, SelectionStatus $status = SelectionStatus::Published): array
{
    $selection = Selection::factory()->create([
        'status' => $status,
        'starts_at' => clockAt('09:00'),
        'published_at' => clockAt('08:00')->subDay(),
        'window_starts' => '09:00',
        'window_ends' => '14:00',
        'weekdays' => [1, 2, 3, 4, 5],
        'slot_minutes' => 30,
        'first_round_ends_at' => clockAt('09:00')->addMinutes(30 * $entries),
    ]);

    $rows = [];

    for ($i = 0; $i < $entries; $i++) {
        $user = User::factory()->withRole(Role::Docente)->create();
        $teacher = Teacher::factory()->create(['user_id' => $user->id]);
        $rows[] = SelectionEntry::factory()->for($selection)->create([
            'teacher_id' => $teacher->id,
            'position' => $i,
            'slot_index' => $i,
            'slot_starts_at' => clockAt('09:00')->addMinutes(30 * $i),
            'slot_ends_at' => clockAt('09:00')->addMinutes(30 * ($i + 1)),
        ]);
    }

    return [$selection, $rows];
}

function advance(CarbonImmutable $now): array
{
    CarbonImmutable::setTestNow($now);

    return app(AdvanceSelections::class)->handle($now);
}

afterEach(fn () => CarbonImmutable::setTestNow());

it('SEL-08: el turno vigente sale de las horas aunque el comando no haya corrido', function () {
    [$selection, $rows] = clockSelection();
    $now = clockAt('09:40');

    $current = SelectionEntry::query()
        ->where('selection_id', $selection->id)
        ->where('slot_starts_at', '<=', $now)
        ->where('slot_ends_at', '>', $now)
        ->get();

    expect($current->pluck('id')->all())->toBe([$rows[1]->id])
        ->and($selection->fresh()->status)->toBe(SelectionStatus::Published)
        ->and($rows[1]->fresh()->status)->toBe(SelectionEntryStatus::Waiting);
});

it('pasa de publicada a en curso en la primera hora y avisa diez minutos antes y al empezar', function () {
    Notification::fake();
    [$selection, $rows] = clockSelection();

    advance(clockAt('08:49'));
    Notification::assertNothingSent();

    advance(clockAt('08:50'));
    Notification::assertSentTo($rows[0]->teacher->user, SelectionTurnStarting::class, fn ($n) => $n->message($rows[0]->teacher->user)->title === 'Tu turno empieza en 10 minutos');
    expect($selection->fresh()->status)->toBe(SelectionStatus::Published);

    advance(clockAt('09:00'));
    expect($selection->fresh()->status)->toBe(SelectionStatus::Running);
    Notification::assertSentToTimes($rows[0]->teacher->user, SelectionTurnStarting::class, 2);
    Notification::assertNotSentTo($rows[1]->teacher->user, SelectionTurnStarting::class);
});

it('Review Focus 2: si el comando corre tarde pone al día los estados y no duplica avisos', function () {
    Notification::fake();
    [$selection, $rows] = clockSelection(3);

    $result = advance(clockAt('09:40'));

    expect($selection->fresh()->status)->toBe(SelectionStatus::Running)
        ->and($rows[0]->fresh()->status)->toBe(SelectionEntryStatus::PendingExtra)
        ->and($rows[1]->fresh()->status)->toBe(SelectionEntryStatus::Waiting)
        ->and($rows[2]->fresh()->status)->toBe(SelectionEntryStatus::Waiting)
        ->and($result['notices'])->toBe(1);

    // El turno de las 09:00 ya pasó: no se avisa tarde. Solo el vigente (09:30).
    Notification::assertNotSentTo($rows[0]->teacher->user, SelectionTurnStarting::class);
    Notification::assertSentToTimes($rows[1]->teacher->user, SelectionTurnStarting::class, 1);

    $again = advance(clockAt('09:40'));
    $later = advance(clockAt('09:41'));

    expect($again)->toBe(['advanced' => 0, 'notices' => 0])
        ->and($later)->toBe(['advanced' => 0, 'notices' => 0]);
    Notification::assertSentToTimes($rows[1]->teacher->user, SelectionTurnStarting::class, 1);
});

it('SEL-09: arma la ronda extra al menos 30 minutos después con los pendientes en el mismo orden', function () {
    Notification::fake();
    [$selection, $rows] = clockSelection(3);
    // El 1 y el 2 comparten turno; el 0 cierra a tiempo.
    $rows[2]->update(['slot_index' => 1, 'slot_starts_at' => $rows[1]->slot_starts_at, 'slot_ends_at' => $rows[1]->slot_ends_at]);
    $selection->update(['first_round_ends_at' => clockAt('10:00')]);
    $rows[0]->update(['status' => SelectionEntryStatus::Done, 'closed_at' => clockAt('09:10')]);
    $rows0 = $rows[0];
    $extra = SelectionEntry::factory()->for($selection)->create([
        'teacher_id' => Teacher::factory()->create(['user_id' => User::factory()->withRole(Role::Docente)->create()->id])->id,
        'position' => 3, 'slot_index' => 2,
        'slot_starts_at' => clockAt('10:00'), 'slot_ends_at' => clockAt('10:30'),
    ]);
    $selection->update(['first_round_ends_at' => clockAt('10:30')]);

    advance(clockAt('10:30'));

    $selection->refresh();
    $rows = collect([$rows[1], $rows[2], $extra])->map->fresh();

    expect($selection->status)->toBe(SelectionStatus::ExtraRound)
        ->and($selection->extra_round_starts_at->equalTo(clockAt('11:00')))->toBeTrue()
        ->and($selection->extra_round_ends_at->equalTo(clockAt('12:00')))->toBeTrue()
        ->and($rows[0]->extra_slot_starts_at->equalTo(clockAt('11:00')))->toBeTrue()
        ->and($rows[1]->extra_slot_starts_at->equalTo(clockAt('11:00')))->toBeTrue()
        ->and($rows[1]->extra_slot_ends_at->equalTo(clockAt('11:30')))->toBeTrue()
        ->and($rows[2]->extra_slot_starts_at->equalTo(clockAt('11:30')))->toBeTrue()
        ->and($rows[0]->status)->toBe(SelectionEntryStatus::PendingExtra)
        ->and($rows[0]->notified_extra_at)->not->toBeNull();

    Notification::assertSentTo($rows[0]->teacher->user, SelectionExtraRoundScheduled::class);
    Notification::assertSentTo($extra->teacher->user, SelectionExtraRoundScheduled::class);
    $done = $rows0->fresh();
    expect($done->status)->toBe(SelectionEntryStatus::Done)->and($done->extra_slot_starts_at)->toBeNull();
    Notification::assertNotSentTo($done->teacher->user, SelectionExtraRoundScheduled::class);

    // Idempotente: otra corrida no rearma ni reenvía.
    $before = $selection->fresh();
    expect(advance(clockAt('10:31')))->toBe(['advanced' => 0, 'notices' => 0]);
    expect($selection->fresh()->extra_round_starts_at->equalTo($before->extra_round_starts_at))->toBeTrue();
});

it('al terminar la ronda extra deja sin resolver a los pendientes y avisa al jefe y al JPD', function () {
    Notification::fake();
    [$selection, $rows] = clockSelection(1);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();
    $jpd = User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $selection->modality_id, 'department_id' => $selection->department_id])->create();
    $directivo = User::factory()->withRole(Role::Directivo)->create();

    advance(clockAt('09:30'));
    expect($selection->fresh()->status)->toBe(SelectionStatus::ExtraRound);

    advance(clockAt('10:15'));
    Notification::assertNotSentTo($jefe, SelectionFinishedWithPending::class);
    expect($selection->fresh()->status)->toBe(SelectionStatus::ExtraRound);

    advance(clockAt('10:30'));

    $selection->refresh();
    expect($selection->status)->toBe(SelectionStatus::Finished)
        ->and($selection->finished_at)->not->toBeNull()
        ->and($rows[0]->fresh()->status)->toBe(SelectionEntryStatus::Unresolved);

    Notification::assertSentTo($jefe, SelectionFinishedWithPending::class, fn ($n) => str_contains($n->message($jefe)->body, $rows[0]->teacher->name));
    Notification::assertSentTo($jpd, SelectionFinishedWithPending::class);
    Notification::assertNotSentTo($directivo, SelectionFinishedWithPending::class);

    expect(advance(clockAt('10:31')))->toBe(['advanced' => 0, 'notices' => 0]);
    Notification::assertSentToTimes($jefe, SelectionFinishedWithPending::class, 1);
});

it('sin pendientes la selección termina directo sin avisar', function () {
    Notification::fake();
    [$selection, $rows] = clockSelection(1);
    $jefe = User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $selection->department_id])->create();
    $rows[0]->update(['status' => SelectionEntryStatus::Done, 'closed_at' => clockAt('09:05')]);

    advance(clockAt('09:30'));

    expect($selection->fresh()->status)->toBe(SelectionStatus::Finished)
        ->and($rows[0]->fresh()->status)->toBe(SelectionEntryStatus::Done);
    Notification::assertNothingSentTo($jefe);
});

it('no toca borradores ni canceladas', function () {
    [$draft] = clockSelection(1, SelectionStatus::Draft);
    [$cancelled] = clockSelection(1, SelectionStatus::Cancelled);

    expect(advance(clockAt('12:00')))->toBe(['advanced' => 0, 'notices' => 0])
        ->and($draft->fresh()->status)->toBe(SelectionStatus::Draft)
        ->and($cancelled->fresh()->status)->toBe(SelectionStatus::Cancelled);
});

it('el comando corre la Action', function () {
    CarbonImmutable::setTestNow(clockAt('08:00'));
    $this->artisan('staffing:advance-selections')->assertSuccessful();
});
