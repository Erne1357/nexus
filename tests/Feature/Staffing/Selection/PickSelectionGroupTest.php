<?php

use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Facilities\Enums\RoomType;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\AssignTeacher;
use App\Modules\Staffing\Actions\Selection\FinishMyLoad;
use App\Modules\Staffing\Actions\Selection\PickSelectionGroup;
use App\Modules\Staffing\Actions\Selection\ReleaseSelectionGroup;
use App\Modules\Staffing\Models\GroupTeacherAssignment;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Staffing\SelectionWorld;

/*
| PickSelectionGroup y ReleaseSelectionGroup (tarea 1.33, diseño 6.5): en su turno, el docente toma un grupo libre de
| las cargas incluidas con un salón elegible para todas sus sesiones, y lo suelta mientras dure su turno.
*/

beforeEach(function () {
    $this->freezeTime();
    $this->world = SelectionWorld::create();
    $this->teacher = $this->world->teacher(base: 5);
    $this->group = $this->world->group('A', [[1, '09:00', '10:00'], [3, '09:00', '10:00']]);
    $this->room = $this->world->room('A1');
});

function pickSelectionGroupFor(Teacher $teacher, Group $group, ?Room $room): GroupTeacherAssignment
{
    return app(PickSelectionGroup::class)->handle(test()->world->selection, $teacher, $group, $room, $teacher->user);
}

function pickSelectionErrors(Closure $callback): array
{
    try {
        $callback();
    } catch (ValidationException $exception) {
        return $exception->errors();
    }

    return [];
}

it('fuera de su turno no puede tomar grupos', function () {
    $later = $this->world->teacher(base: 5, slotStartsAt: CarbonImmutable::now()->addMinutes(25), position: 2, name: 'Docente Dos');

    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($later, $this->group, $this->room));

    expect($errors['group'])->toBe(['No es tu turno.'])
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('Review Focus 1: dos docentes del mismo turno toman el mismo grupo, uno gana y el otro recibe «Ya lo tomó»', function () {
    $peer = $this->world->teacher(base: 5, position: 2, entry: ['slot_index' => 0], name: 'Docente Par');

    pickSelectionGroupFor($this->teacher, $this->group, $this->room);
    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($peer, $this->group, $this->room));

    expect($errors['group'])->toBe(['Ya lo tomó Docente Uno'])
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->sole()->teacher_id)->toBe($this->teacher->id);
});

it('serializa las elecciones de la selección con un candado consultivo antes de la carga y del grupo', function () {
    $queries = [];
    DB::listen(function ($query) use (&$queries) {
        $queries[] = mb_strtolower($query->sql);
    });

    pickSelectionGroupFor($this->teacher, $this->group, $this->room);

    $advisory = collect($queries)->search(fn (string $sql) => str_contains($sql, 'pg_advisory_xact_lock'));
    $lockLoad = collect($queries)->search(fn (string $sql) => str_contains($sql, 'academic_loads') && str_contains($sql, 'for share'));
    $lockGroup = collect($queries)->search(fn (string $sql) => str_contains($sql, 'from "groups"') && str_contains($sql, 'for update'));

    expect($advisory)->not->toBeFalse()
        ->and($advisory)->toBeLessThan($lockLoad)
        ->and($lockLoad)->toBeLessThan($lockGroup);
});

it('HC-01: no puede tomar un grupo que choca con otro suyo', function () {
    $mine = $this->world->group('B', [[1, '09:00', '10:00']]);
    pickSelectionGroupFor($this->teacher, $mine, $this->world->room('A2'));

    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $this->group, $this->room));

    expect($errors)->toHaveKey('teacher')
        ->and(implode(' ', $errors['teacher']))->toContain('Docente Uno ya da')->toContain('grupo B')
        ->and($this->group->fresh()->teacher_id)->toBeNull();
});

it('toma el grupo con el salón en todas sus sesiones', function () {
    $assignment = pickSelectionGroupFor($this->teacher, $this->group, $this->room);

    expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(GroupSession::query()->where('group_id', $this->group->id)->pluck('room_id')->unique()->all())->toBe([$this->room->id])
        ->and($assignment)
        ->teacher_id->toBe($this->teacher->id)
        ->assigned_by->toBe($this->teacher->user_id)
        ->reason->toBe('Orden de selección, turno 1')
        ->and(Activity::query()->where('event', 'teacher_assigned')->sole()->properties['reason'])->toBe('Orden de selección, turno 1');
});

it('rechaza un salón que no es elegible (SEL-02)', function () {
    $laboratory = $this->world->room('L1', RoomType::Laboratorio);
    $lab = $this->world->group('L', [[2, '09:00', '10:00']], space: RequiredSpace::Laboratorio);

    $basic = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $this->group, $laboratory));
    $labInAula = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $lab, $this->room));

    expect($basic['room'])->toBe(['El salón L1 no se puede elegir para este grupo: Solo para materias de laboratorio.'])
        ->and($labInAula['room'])->toBe(['El salón A1 no se ofrece para este grupo.'])
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and($lab->fresh()->teacher_id)->toBeNull()
        ->and(GroupSession::query()->whereIn('group_id', [$this->group->id, $lab->id])->whereNotNull('room_id')->count())->toBe(0);
});

it('sin salón solo se toma cuando no hay ninguno elegible', function () {
    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $this->group, null));
    $lab = $this->world->group('L', [[2, '09:00', '10:00']], space: RequiredSpace::Laboratorio);

    expect($errors['room'])->toBe(['Elige un salón para el grupo.'])
        ->and(pickSelectionGroupFor($this->teacher, $lab, null)->exists)->toBeTrue()
        ->and($lab->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('no toma un grupo fuera de las cargas incluidas', function () {
    $foreign = Group::factory()->create(['term_id' => $this->world->term->id, 'campus_id' => $this->world->campus->id, 'modality_id' => $this->world->selection->modality_id, 'code' => 'X']);
    GroupSession::factory()->for($foreign)->on(2, '09:00', '10:00')->create();

    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $foreign, $this->room));

    expect($errors['group'])->toBe(['El grupo no es de esta selección.']);
});

it('suelta su grupo en su turno: quita al docente y los salones', function () {
    pickSelectionGroupFor($this->teacher, $this->group, $this->room);

    app(ReleaseSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->teacher->user);

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupSession::query()->where('group_id', $this->group->id)->whereNotNull('room_id')->count())->toBe(0)
        ->and(GroupTeacherAssignment::query()->current()->where('group_id', $this->group->id)->count())->toBe(0)
        ->and(Activity::query()->where('event', 'teacher_unassigned')->sole()->properties['reason'])->toBe('Lo soltó en su turno de la orden de selección');
});

it('no suelta un grupo que no es suyo', function () {
    $peer = $this->world->teacher(base: 5, position: 2, name: 'Docente Par');
    pickSelectionGroupFor($peer, $this->group, $this->room);

    $errors = pickSelectionErrors(fn () => app(ReleaseSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->teacher->user));

    expect($errors['group'])->toBe(['Ese grupo no es tuyo.'])
        ->and($this->group->fresh()->teacher_id)->toBe($peer->id);
});

it('después de terminar ya no puede tomar ni soltar', function () {
    pickSelectionGroupFor($this->teacher, $this->group, $this->room);
    app(FinishMyLoad::class)->handle($this->world->selection, $this->teacher, false, $this->teacher->user);
    $other = $this->world->group('C', [[2, '11:00', '12:00']]);

    $pick = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $other, $this->world->room('A3')));
    $release = pickSelectionErrors(fn () => app(ReleaseSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->teacher->user));

    expect($pick['group'])->toBe(['Ya terminaste tu carga: solo tu jefe de departamento o el JPD la cambian.'])
        ->and($release['group'])->toBe(['Ya terminaste tu carga: solo tu jefe de departamento o el JPD la cambian.'])
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($other->fresh()->teacher_id)->toBeNull();
});

it('un docente no puede elegir, soltar ni terminar por otro', function () {
    $peer = $this->world->teacher(base: 5, position: 2, name: 'Docente Par');
    $selection = $this->world->selection;

    expect(fn () => app(PickSelectionGroup::class)->handle($selection, $this->teacher, $this->group, $this->room, $peer->user))->toThrow(AuthorizationException::class)
        ->and(fn () => app(PickSelectionGroup::class)->handle($selection, $this->teacher, $this->group, $this->room, $this->world->admin))->toThrow(AuthorizationException::class)
        ->and($this->group->fresh()->teacher_id)->toBeNull();

    pickSelectionGroupFor($this->teacher, $this->group, $this->room);

    expect(fn () => app(ReleaseSelectionGroup::class)->handle($selection, $this->teacher, $this->group, $peer->user))->toThrow(AuthorizationException::class)
        ->and(fn () => app(FinishMyLoad::class)->handle($selection, $this->teacher, false, $peer->user))->toThrow(AuthorizationException::class)
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($this->world->entryOf($this->teacher)->closed_at)->toBeNull();
});

it('un docente sin cuenta no elige', function () {
    $noAccount = $this->world->teacher(base: 5, position: 2, name: 'Docente Sin Cuenta');
    $noAccount->update(['user_id' => null]);

    expect(fn () => app(PickSelectionGroup::class)->handle($this->world->selection, $noAccount, $this->group, $this->room, $this->world->admin))->toThrow(AuthorizationException::class);
});

it('HC-09: no puede tomar un grupo a una hora en que no está disponible', function () {
    TeacherAvailability::factory()->create(['teacher_id' => $this->teacher->id, 'term_id' => $this->world->term->id, 'day' => 3, 'starts_at' => '09:00', 'ends_at' => '10:00']);

    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $this->group, $this->room));

    expect($errors)->toHaveKey('teacher')
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and(GroupTeacherAssignment::query()->count())->toBe(0);
});

it('fuera de su turno no suelta su grupo', function () {
    pickSelectionGroupFor($this->teacher, $this->group, $this->room);
    $this->travel(31)->minutes();

    $errors = pickSelectionErrors(fn () => app(ReleaseSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->teacher->user));

    expect($errors['group'])->toBe(['No es tu turno.'])
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and(GroupSession::query()->where('group_id', $this->group->id)->whereNotNull('room_id')->count())->toBe(2);
});

it('no suelta un grupo que le asignó el jefe', function () {
    app(AssignTeacher::class)->handle($this->group, $this->teacher, $this->world->admin);

    $errors = pickSelectionErrors(fn () => app(ReleaseSelectionGroup::class)->handle($this->world->selection, $this->teacher, $this->group, $this->teacher->user));

    expect($errors['group'])->toBe(['Solo puedes soltar los grupos que tomaste en la orden de selección.'])
        ->and($this->group->fresh()->teacher_id)->toBe($this->teacher->id);
});

it('1.62: con la carga en borrador no toma grupos aunque sí se editen horarios y salones (confirmado el 26 de noviembre de 2026)', function () {
    $this->world->load->update(['status' => LoadStatus::Draft]);

    $errors = pickSelectionErrors(fn () => pickSelectionGroupFor($this->teacher, $this->group, $this->room));

    expect($errors['group'])->toBe(['La carga está en Borrador: los docentes se asignan en Asignación, Ajustes o Re-análisis.'])
        ->and($this->group->fresh()->teacher_id)->toBeNull();
});
