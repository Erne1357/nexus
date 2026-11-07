<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\SetGroupTeacher;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SetGroupTeacher: la única escritura de groups.teacher_id (ADR-0020). La llama la asignación de Staffing; rechaza
| con el mensaje de HC-01 un docente que ya da clase a esa hora, en cualquier campus.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->teacher = Teacher::factory()->create(['name' => 'Ana Pérez']);
    $this->group = Group::factory()->create(['code' => 'D']);
    GroupSession::factory()->for($this->group)->on(1, '10:00', '12:00')->create();
});

function setGroupTeacher(Group $group, ?Teacher $teacher, User $user): Group
{
    return app(SetGroupTeacher::class)->handle($group, $teacher, $user);
}

it('asigna el docente, que pasa a sus sesiones, y deja la bitácora con el anterior y el nuevo', function () {
    setGroupTeacher($this->group, $this->teacher, $this->user);

    $entry = Activity::sole();

    expect($this->group->fresh()->teacher_id)->toBe($this->teacher->id)
        ->and($this->group->sessions()->sole()->teacher_id)->toBe($this->teacher->id)
        ->and($entry)
        ->event->toBe('updated')
        ->description->toBe('Asignó docente al grupo D')
        ->and($entry->causer->is($this->user))->toBeTrue()
        ->and($entry->subject->is($this->group))->toBeTrue()
        ->and($entry->attribute_changes->toArray())->toBe([
            'attributes' => ['teacher_id' => $this->teacher->id],
            'old' => ['teacher_id' => null],
        ]);
});

it('quita el docente y lo registra', function () {
    $this->group->update(['teacher_id' => $this->teacher->id]);

    setGroupTeacher($this->group, null, $this->user);

    expect($this->group->fresh()->teacher_id)->toBeNull()
        ->and(Activity::sole()->description)->toBe('Quitó el docente del grupo D');
});

it('HC-01: rechaza con su mensaje a un docente que ya da clase a esa hora en otro campus, sin escribir', function () {
    $busy = Group::factory()->create(['term_id' => $this->group->term_id, 'teacher_id' => $this->teacher->id, 'code' => 'F',
        'subject_id' => Subject::factory()->create(['name' => 'Química'])->id]);
    GroupSession::factory()->for($busy)->on(1, '11:00', '13:00')->create();

    expect(fn () => setGroupTeacher($this->group, $this->teacher, $this->user))
        ->toThrow(fn (ValidationException $exception) => expect($exception->errors()['teacher'])
            ->toBe(['Ana Pérez ya da Química, grupo F el lunes de 11:00 a 13:00.']))
        ->and($this->group->fresh()->teacher_id)->toBeNull()
        ->and(Activity::count())->toBe(0);
});

it('HC-01: si otra sesión gana la carrera, la exclusión de la base lo rechaza con el mismo id', function () {
    $busy = Group::factory()->create(['term_id' => $this->group->term_id, 'teacher_id' => $this->teacher->id]);
    $group = $this->group;

    Group::updating(function (Group $updating) use ($group, $busy): void {
        if ($updating->is($group)) {
            DB::table('group_sessions')->insert([
                'group_id' => $busy->id, 'day' => 1, 'starts_at' => '10:00', 'ends_at' => '11:00', 'kind' => 'teoria',
                'term_id' => $busy->term_id, 'campus_id' => $busy->campus_id,
            ]);
        }
    });

    expect(fn () => setGroupTeacher($this->group, $this->teacher, $this->user))
        ->toThrow(ValidationException::class, 'HC-01');
});

it('un grupo cancelado no recibe docente', function () {
    $this->group->update(['status' => 'cancelled']);

    expect(fn () => setGroupTeacher($this->group, $this->teacher, $this->user))
        ->toThrow(ValidationException::class, 'El grupo está cancelado');
});

it('con el mismo docente no escribe, no registra ni avisa', function () {
    $this->group->update(['teacher_id' => $this->teacher->id]);
    Event::fake([GroupTeacherChanged::class]);

    setGroupTeacher($this->group, $this->teacher, $this->user);

    expect(Activity::count())->toBe(0);
    Event::assertNotDispatched(GroupTeacherChanged::class);
});

it('avisa con GroupTeacherChanged el docente anterior y el nuevo, solo después del commit', function () {
    $heard = [];
    Event::listen(GroupTeacherChanged::class, function (GroupTeacherChanged $event) use (&$heard): void {
        $heard[] = $event;
    });

    try {
        DB::transaction(function (): void {
            setGroupTeacher($this->group, $this->teacher, $this->user);

            throw new RuntimeException('Falla algo después, en la misma transacción.');
        });
    } catch (RuntimeException) {
        //
    }

    expect($heard)->toBe([]);

    setGroupTeacher($this->group->fresh(), $this->teacher, $this->user);

    expect($heard)->toHaveCount(1)
        ->and($heard[0]->group->is($this->group))->toBeTrue()
        ->and($heard[0]->oldTeacherId)->toBeNull()
        ->and($heard[0]->newTeacherId)->toBe($this->teacher->id);
});
