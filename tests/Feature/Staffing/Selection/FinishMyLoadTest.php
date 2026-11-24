<?php

use App\Modules\Core\Models\Modality;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherHourAllowance;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Enums\SelectionEntrySource;
use App\Modules\Staffing\Enums\SelectionEntryStatus;
use App\Modules\Staffing\Enums\SelectionStatus;
use App\Modules\Staffing\Models\SelectionEntry;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| FinishMyLoad (tarea 1.33, diseño 6.6, SEL-05 y SEL-12): el docente cierra su carga en su turno. Dentro de su base ±2
| termina; fuera, solo con la casilla de responsabilidad: con horas de más termina y el validador acepta esas horas
| (HC-08 justificado); con menos pasa a la ronda extra, y en la ronda extra termina.
*/

const FINISH_OUT_OF_BAND = 'Es responsabilidad del docente cubrir sus horas de acuerdo con su base: marca la casilla para continuar.';

beforeEach(function () {
    $this->freezeTime();
});

function finishLoadWorld(int $base, SelectionStatus $status = SelectionStatus::Running, array $entry = []): array
{
    $world = SelectionWorld::create($status);
    $teacher = $world->teacher(base: $base, entry: $entry);
    $group = $world->group('A', [[1, '09:00', '10:00']], theory: 3, practice: 2);
    app(PickSelectionGroup::class)->handle($world->selection, $teacher, $group, $world->room('A1'), $teacher->user);

    return [$world, $teacher, $group];
}

function finishLoadFor(SelectionWorld $world, Teacher $teacher, bool $acknowledged): SelectionEntry
{
    return app(FinishMyLoad::class)->handle($world->selection, $teacher, $acknowledged, $teacher->user);
}

function finishLoadHoursViolations(SelectionWorld $world, Teacher $teacher): array
{
    $validator = app(ScheduleValidator::class);
    $validator->forget();

    return $validator->validateTeacher($teacher, $world->term)->forRule('HC-08');
}

function finishLoadErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('SEL-05: dentro de su base ±2 termina sin la casilla', function () {
    [$world, $teacher] = finishLoadWorld(base: 7);

    $entry = finishLoadFor($world, $teacher, false);

    expect($entry->fresh())
        ->status->toBe(SelectionEntryStatus::Done)
        ->closed_hours->toBe(5)
        ->closed_at->not->toBeNull()
        ->out_of_band_acknowledged_at->toBeNull()
        ->and(Activity::query()->where('event', 'selection_load_finished')->sole()->properties['band'])->toBe('within')
        ->and(TeacherHourAllowance::query()->sole()->hours)->toBe(5);
});

it('SEL-12: cerrar dentro de la banda con más horas que su límite de Nexus no deja choque duro de HC-08', function () {
    [$world, $teacher] = finishLoadWorld(base: 3, entry: ['base_hours' => 5]);

    expect(finishLoadHoursViolations($world, $teacher))->not->toBeEmpty();

    finishLoadFor($world, $teacher, false);

    expect(finishLoadHoursViolations($world, $teacher))->toBe([]);
});

it('SEL-12: las horas autorizadas son todo lo que HC-08 le cuenta al cerrar: otras modalidades y horas externas', function () {
    [$world, $teacher] = finishLoadWorld(base: 2, entry: ['base_hours' => 5]);
    $elsewhere = Group::factory()->create(['term_id' => $world->term->id, 'modality_id' => Modality::factory()->create()->id, 'teacher_id' => $teacher->id]);
    GroupSession::factory()->for($elsewhere)->on(4, '16:00', '18:00')->create();
    TeacherExternalLoad::factory()->create(['teacher_id' => $teacher->id, 'term_id' => $world->term->id, 'hours' => 2]);

    $entry = finishLoadFor($world, $teacher, false);

    expect($entry->fresh()->closed_hours)->toBe(5)
        ->and(TeacherHourAllowance::query()->sole()->hours)->toBe(12)
        ->and(finishLoadHoursViolations($world, $teacher))->toBe([]);

    $extra = $world->group('B', [[2, '11:00', '12:00']], theory: 1, practice: 0);
    app(AssignTeacher::class)->handle($extra, $teacher, $world->admin);

    expect(finishLoadHoursViolations($world, $teacher))->not->toBeEmpty();
});

it('SEL-05: cerrar fuera de ±2 sin la casilla se rechaza', function () {
    [$under, $underTeacher] = finishLoadWorld(base: 20);
    [$over, $overTeacher] = finishLoadWorld(base: 2);

    $short = finishLoadErrors(fn () => finishLoadFor($under, $underTeacher, false));
    $long = finishLoadErrors(fn () => finishLoadFor($over, $overTeacher, false));

    expect($short['acknowledged'])->toBe([FINISH_OUT_OF_BAND])
        ->and($long['acknowledged'])->toBe([FINISH_OUT_OF_BAND])
        ->and($under->entryOf($underTeacher)->status)->toBe(SelectionEntryStatus::Waiting)
        ->and($over->entryOf($overTeacher)->closed_at)->toBeNull();
});

it('SEL-05: cerrar con menos horas y la casilla lo manda a la ronda extra', function () {
    [$world, $teacher] = finishLoadWorld(base: 20);

    $entry = finishLoadFor($world, $teacher, true);
    $again = finishLoadErrors(fn () => app(PickSelectionGroup::class)->handle($world->selection, $teacher, $world->group('B', [[2, '09:00', '10:00']]), $world->room('A2'), $teacher->user));

    expect($entry->fresh())
        ->status->toBe(SelectionEntryStatus::PendingExtra)
        ->closed_hours->toBe(5)
        ->out_of_band_acknowledged_at->not->toBeNull()
        ->and($again['group'])->toBe(['Ya cerraste tu carga en esta ronda: vuelves a elegir en la ronda extra.']);
});

it('SEL-05: en la ronda extra, cerrar con menos horas y la casilla termina', function () {
    $now = CarbonImmutable::now();
    [$world, $teacher] = finishLoadWorld(base: 20, status: SelectionStatus::ExtraRound, entry: [
        'status' => SelectionEntryStatus::PendingExtra,
        'slot_starts_at' => $now->subDay(),
        'slot_ends_at' => $now->subDay()->addMinutes(30),
        'extra_slot_starts_at' => $now->subMinutes(5),
        'extra_slot_ends_at' => $now->addMinutes(25),
    ]);

    expect(finishLoadFor($world, $teacher, true)->fresh()->status)->toBe(SelectionEntryStatus::Done);
});

it('SEL-05: con horas de más y la casilla termina y le da al docente esas horas en el periodo', function () {
    [$world, $teacher] = finishLoadWorld(base: 2);

    $entry = finishLoadFor($world, $teacher, true);
    $allowance = TeacherHourAllowance::query()->sole();

    expect($entry->fresh()->status)->toBe(SelectionEntryStatus::Done)
        ->and($allowance)
        ->teacher_id->toBe($teacher->id)
        ->term_id->toBe($world->term->id)
        ->hours->toBe(5)
        ->granted_by->toBe($teacher->user_id);
});

it('un docente fuera del CSV se mide contra su base de Nexus', function () {
    [$world, $teacher] = finishLoadWorld(base: 5, entry: ['source' => SelectionEntrySource::Appended, 'base_hours' => 0]);

    expect(finishLoadFor($world, $teacher, false)->fresh()->status)->toBe(SelectionEntryStatus::Done);
});

it('fuera de su turno no cierra su carga', function () {
    [$world, $teacher] = finishLoadWorld(base: 5);
    $this->travel(31)->minutes();

    expect(finishLoadErrors(fn () => finishLoadFor($world, $teacher, false))['group'])->toBe(['No es tu turno.']);
});

it('SEL-12: las horas de más aceptadas con la casilla no son choque duro de HC-08; agregarle más sí', function () {
    [$world, $teacher] = finishLoadWorld(base: 2);
    $hoursViolations = fn (): array => finishLoadHoursViolations($world, $teacher);

    expect($hoursViolations())->not->toBeEmpty();

    finishLoadFor($world, $teacher, true);

    expect($hoursViolations())->toBe([]);

    $extra = $world->group('B', [[2, '11:00', '12:00']]);
    app(AssignTeacher::class)->handle($extra, $teacher, $world->admin);

    expect($hoursViolations())->not->toBeEmpty()
        ->and(collect($hoursViolations())->every(fn ($violation) => $violation->severity->value === 'hard'))->toBeTrue()
        ->and(Group::query()->where('teacher_id', $teacher->id)->count())->toBe(2);
});

/**
 * El docente tomó su grupo en su turno, no terminó, pasó la ronda extra sin cerrar y la selección terminó: queda sin
 * resolver (SEL-09).
 *
 * @return array{0: SelectionWorld, 1: Teacher}
 */
function unresolvedAfterSelection(int $base): array
{
    [$world, $teacher] = finishLoadWorld(base: $base);
    $world->selection->update(['status' => SelectionStatus::Finished, 'finished_at' => now()->addDay()]);
    $world->entryOf($teacher)->update(['status' => SelectionEntryStatus::Unresolved]);
    test()->travel(2)->days();

    return [$world, $teacher];
}

it('SEL-14: sin resolver, con la selección terminada, confirma su carga con la casilla; autoriza HC-08 y quita el choque duro', function () {
    [$world, $teacher] = unresolvedAfterSelection(base: 2);

    expect(finishLoadHoursViolations($world, $teacher))->not->toBeEmpty();

    $missing = finishLoadErrors(fn () => app(FinishMyLoad::class)->confirm($world->selection, $teacher, false, $teacher->user));

    expect($missing['acknowledged'])->toBe([FINISH_OUT_OF_BAND])
        ->and($world->entryOf($teacher)->status)->toBe(SelectionEntryStatus::Unresolved)
        ->and(finishLoadHoursViolations($world, $teacher))->not->toBeEmpty();

    $entry = app(FinishMyLoad::class)->confirm($world->selection, $teacher, true, $teacher->user);

    expect($entry->fresh())
        ->status->toBe(SelectionEntryStatus::Done)
        ->closed_hours->toBe(5)
        ->out_of_band_acknowledged_at->not->toBeNull()
        ->and(TeacherHourAllowance::query()->sole()->hours)->toBe(5)
        ->and(finishLoadHoursViolations($world, $teacher))->toBe([])
        ->and(Activity::query()->where('event', 'selection_load_confirmed')->sole()->properties['band'])->toBe('over');
});

it('SEL-14: dentro de su margen confirma sin la casilla', function () {
    [$world, $teacher] = unresolvedAfterSelection(base: 5);

    expect(app(FinishMyLoad::class)->confirm($world->selection, $teacher, false, $teacher->user)->fresh()->status)->toBe(SelectionEntryStatus::Done);
});

it('SEL-14: otro docente no confirma por él', function () {
    [$world, $teacher] = unresolvedAfterSelection(base: 2);
    $other = $world->teacher(base: 2, name: 'Docente Otro Ficticio', position: 2, entry: ['status' => SelectionEntryStatus::Unresolved]);

    expect(fn () => app(FinishMyLoad::class)->confirm($world->selection, $teacher, true, $other->user))->toThrow(AuthorizationException::class)
        ->and($world->entryOf($teacher)->status)->toBe(SelectionEntryStatus::Unresolved)
        ->and(TeacherHourAllowance::query()->count())->toBe(0);
});

it('SEL-14: solo confirma un renglón sin resolver, y confirmar no deja tomar ni soltar fuera de turno', function () {
    [$world, $teacher] = finishLoadWorld(base: 5);

    expect(finishLoadErrors(fn () => app(FinishMyLoad::class)->confirm($world->selection, $teacher, false, $teacher->user))['group'])
        ->toBe(['Solo confirma su carga quien quedó sin resolver al terminar la selección.']);

    [$finished, $unresolved] = unresolvedAfterSelection(base: 5);
    app(FinishMyLoad::class)->confirm($finished->selection, $unresolved, false, $unresolved->user);

    expect(finishLoadErrors(fn () => app(FinishMyLoad::class)->confirm($finished->selection, $unresolved, false, $unresolved->user))['group'])
        ->toBe(['Solo confirma su carga quien quedó sin resolver al terminar la selección.'])
        ->and(finishLoadErrors(fn () => app(PickSelectionGroup::class)->handle($finished->selection, $unresolved, $finished->group('B', [[2, '09:00', '10:00']]), $finished->room('A2'), $unresolved->user))['group'])
        ->toBe(['Ya terminaste tu carga: solo tu jefe de departamento o el JPD la cambian.']);
});
