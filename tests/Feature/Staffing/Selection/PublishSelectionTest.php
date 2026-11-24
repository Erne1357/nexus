<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\Selection\CancelSelection;
use App\Modules\Staffing\Actions\Selection\PublishSelection;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\Selection;
use App\Modules\Staffing\Models\SelectionEntry;
use App\Modules\Staffing\Notifications\SelectionTurnPublished;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Publicar una selección (SEL-11, diseño 6.2 y 6.12): exige todo resuelto y las materias revisadas, abre las cargas
| incluidas a la asignación sin HC-14 y avisa a cada docente su hora.
*/

/**
 * @return array{selection: Selection, load: AcademicLoad, subject: Subject, teacherUser: User, entry: SelectionEntry}
 */
function pubScenario(LoadStatus $loadStatus = LoadStatus::Draft, ?string $space = 'aula', ?CarbonImmutable $startsAt = null): array
{
    $selection = Selection::factory()->create([
        'starts_at' => $startsAt ?? CarbonImmutable::now()->addDays(3)->setTime(9, 0),
    ]);
    $load = AcademicLoad::factory()->inStatus($loadStatus)->create([
        'term_id' => $selection->term_id, 'modality_id' => $selection->modality_id, 'department_id' => $selection->department_id,
    ]);
    $selection->loads()->attach($load->id);
    $subject = Subject::factory()->create(['department_id' => $selection->department_id, 'required_space' => $space]);
    Group::factory()->create(['term_id' => $selection->term_id, 'modality_id' => $selection->modality_id, 'subject_id' => $subject->id]);
    $user = User::factory()->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);
    $entry = SelectionEntry::factory()->for($selection)->create([
        'teacher_id' => $teacher->id, 'position' => 1, 'slot_index' => 0,
        'slot_starts_at' => $selection->starts_at, 'slot_ends_at' => $selection->starts_at->addMinutes(30),
    ]);

    return ['selection' => $selection, 'load' => $load, 'subject' => $subject, 'teacherUser' => $user, 'entry' => $entry];
}

beforeEach(function () {
    $this->actor = User::factory()->admin()->create();
});

it('SEL-11: publicar pasa las cargas incluidas de borrador a asignación sin exigir HC-14', function () {
    Notification::fake();
    $s = pubScenario(LoadStatus::Draft);

    $published = app(PublishSelection::class)->handle($s['selection'], $this->actor);

    expect($published->status)->toBe(SelectionStatus::Published)
        ->and($published->published_at)->not->toBeNull()
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($s['load']->transitions()->count())->toBe(1);
    $row = $s['load']->transitions()->firstOrFail();
    expect($row->from_status)->toBe(LoadStatus::Draft)
        ->and($row->to_status)->toBe(LoadStatus::Staffing)
        ->and($row->user_id)->toBe($this->actor->id);
    expect(Activity::query()->where('event', 'selection_published')->count())->toBe(1);
});

it('publicar deja como están las cargas que ya estaban en asignación', function () {
    Notification::fake();
    $s = pubScenario(LoadStatus::Staffing);

    app(PublishSelection::class)->handle($s['selection'], $this->actor);

    expect($s['load']->fresh()->status)->toBe(LoadStatus::Staffing)
        ->and($s['load']->transitions()->count())->toBe(0);
});

it('rechaza publicar con una carga que ya cerró y no cambia nada', function () {
    Notification::fake();
    $s = pubScenario(LoadStatus::Closed);

    expect(fn () => app(PublishSelection::class)->handle($s['selection'], $this->actor))->toThrow(ValidationException::class);
    expect($s['selection']->fresh()->status)->toBe(SelectionStatus::Draft);
});

it('publicar avisa a cada docente su hora', function () {
    Notification::fake();
    $s = pubScenario();
    $withoutAccount = SelectionEntry::factory()->for($s['selection'])->create(['teacher_id' => Teacher::factory()->create(['user_id' => null])->id, 'position' => 2]);

    app(PublishSelection::class)->handle($s['selection'], $this->actor);

    Notification::assertSentTo($s['teacherUser'], SelectionTurnPublished::class, function (SelectionTurnPublished $notification) use ($s): bool {
        $message = $notification->message($s['teacherUser'])->toArray();

        return str_starts_with($message['body'], 'Tu turno de selección es el ')
            && str_contains($message['body'], ' de '.$s['entry']->slot_starts_at->setTimezone('America/Ciudad_Juarez')->format('H:i').' a ');
    });
    Notification::assertCount(1);
});

it('no se publica con materias sin tipo de espacio revisado', function () {
    Notification::fake();
    $s = pubScenario(space: null);

    expect(fn () => app(PublishSelection::class)->handle($s['selection'], $this->actor))->toThrow(ValidationException::class);

    expect($s['selection']->fresh()->status)->toBe(SelectionStatus::Draft)
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Draft);

    $s['subject']->update(['required_space' => 'aula']);
    expect(app(PublishSelection::class)->handle($s['selection']->fresh(), $this->actor)->status)->toBe(SelectionStatus::Published);
});

it('no se publica con un renglón sin docente', function () {
    $s = pubScenario();
    SelectionEntry::factory()->for($s['selection'])->create(['teacher_id' => null, 'position' => 2]);

    app(PublishSelection::class)->handle($s['selection'], $this->actor);
})->throws(ValidationException::class);

it('no se publica sin fecha de inicio futura ni sin ser borrador', function () {
    $past = pubScenario(startsAt: CarbonImmutable::now()->subHour());
    expect(fn () => app(PublishSelection::class)->handle($past['selection'], $this->actor))->toThrow(ValidationException::class);

    $s = pubScenario();
    $s['selection']->update(['status' => SelectionStatus::Published]);
    expect(fn () => app(PublishSelection::class)->handle($s['selection'], $this->actor))->toThrow(ValidationException::class);
});

it('cancelar antes de la primera hora deja la selección cancelada', function () {
    $s = pubScenario();

    app(CancelSelection::class)->handle($s['selection'], $this->actor);

    expect($s['selection']->fresh()->status)->toBe(SelectionStatus::Cancelled)
        ->and($s['selection']->fresh()->cancelled_at)->not->toBeNull();
    expect(Activity::query()->where('event', 'selection_cancelled')->count())->toBe(1);
});

it('cancelar después de la primera hora se rechaza', function () {
    $s = pubScenario(startsAt: CarbonImmutable::now()->subMinute());

    expect(fn () => app(CancelSelection::class)->handle($s['selection'], $this->actor))->toThrow(ValidationException::class);
    expect($s['selection']->fresh()->status)->toBe(SelectionStatus::Draft);
});

it('una materia sin revisar de otro campus también impide publicar', function () {
    Notification::fake();
    $s = pubScenario();
    $other = Subject::factory()->create(['department_id' => $s['selection']->department_id, 'required_space' => null]);
    Group::factory()->create(['term_id' => $s['selection']->term_id, 'campus_id' => Campus::factory()->create()->id, 'modality_id' => $s['selection']->modality_id, 'subject_id' => $other->id]);

    expect(fn () => app(PublishSelection::class)->handle($s['selection'], $this->actor))->toThrow(ValidationException::class);
    expect($s['selection']->fresh()->status)->toBe(SelectionStatus::Draft);
});

it('sin cargas incluidas no se publica y lo dice', function () {
    $s = pubScenario();
    $s['selection']->loads()->detach();

    try {
        app(PublishSelection::class)->handle($s['selection'], $this->actor);
        $this->fail('debió rechazar');
    } catch (ValidationException $e) {
        expect(implode(' ', $e->errors()['status']))->toContain('La selección no tiene cargas incluidas')->not->toContain('tipo de espacio');
    }
});

it('el aviso lleva a la oferta, y un renglón sin turno recibe el suyo al recalcular la cola al publicar', function () {
    Notification::fake();
    $s = pubScenario();
    $unslotted = SelectionEntry::factory()->for($s['selection'])->create([
        'teacher_id' => Teacher::factory()->create(['user_id' => User::factory()->create()->id])->id, 'position' => 2, 'slot_starts_at' => null, 'slot_ends_at' => null,
    ]);

    app(PublishSelection::class)->handle($s['selection'], $this->actor);

    Notification::assertSentTo($s['teacherUser'], SelectionTurnPublished::class, function (SelectionTurnPublished $n) use ($s): bool {
        $message = $n->message($s['teacherUser'])->toArray();

        return $message['route'] === 'staffing.offer' && $message['parameters'] === [] && route($message['route']) !== '';
    });
    expect($unslotted->fresh()->slot_starts_at)->not->toBeNull();
    Notification::assertCount(2);
});

it('SEL-10: publicar recalcula la cola dentro del candado: un docente adscrito después del CSV entra al final', function () {
    Notification::fake();
    $s = pubScenario();
    $late = Teacher::factory()->adscribedTo($s['selection']->department)->create(['name' => 'Docente Tardío Ficticio', 'user_id' => User::factory()->create()->id]);

    app(PublishSelection::class)->handle($s['selection'], $this->actor);

    $entry = $s['selection']->entries()->where('teacher_id', $late->id)->first();

    expect($entry)->not->toBeNull()
        ->and($entry->source->value)->toBe('appended')
        ->and($entry->slot_starts_at)->not->toBeNull()
        ->and($entry->position)->toBeGreaterThan($s['entry']->fresh()->position);
    Notification::assertSentTo($late->user, SelectionTurnPublished::class);
});
