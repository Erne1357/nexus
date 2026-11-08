<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Scheduling\Actions\AssignGroupCode;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Spatie\Activitylog\Models\Activity;

/*
| AssignGroupCode: la clave del grupo sigue la hora de inicio más temprana de sus sesiones, como en el SII
| (reglas-de-negocio.md, "Grupos y horarios"), hasta que la carga del departamento se publica.
*/

beforeEach(function () {
    $this->subject = Subject::factory()->create();
    $this->group = Group::factory()->create(['subject_id' => $this->subject->id, 'code' => '1']);
});

function assignCodeSession(Group $group, int $day, string $start, string $end): GroupSession
{
    return GroupSession::factory()->for($group)->on($day, $start, $end)->create();
}

function assignCodeOf(Group $group): string
{
    return app(AssignGroupCode::class)->handle($group)->code;
}

it('toma la letra de la hora de inicio más temprana de sus sesiones', function () {
    assignCodeSession($this->group, 3, '11:00', '13:00');
    assignCodeSession($this->group, 1, '09:30', '11:00');

    expect(assignCodeOf($this->group))->toBe('C')
        ->and($this->group->fresh()->code)->toBe('C');

    $entry = Activity::query()->where('event', 'updated')->sole();
    expect($entry->description)->toBe('Cambió la clave del grupo de 1 a C')
        ->and($entry->subject_id)->toBe($this->group->id)
        ->and($entry->attribute_changes->toArray())->toBe(['attributes' => ['code' => 'C'], 'old' => ['code' => '1']]);
});

it('si otro grupo de la materia y el periodo ya tiene la letra, le pone un número', function () {
    foreach (['D', 'D2'] as $code) {
        Group::factory()->create(['subject_id' => $this->subject->id, 'term_id' => $this->group->term_id, 'code' => $code]);
    }
    assignCodeSession($this->group, 1, '10:00', '12:00');

    expect(assignCodeOf($this->group))->toBe('D3');
});

it('las letras de otra materia u otro periodo no cuentan', function () {
    Group::factory()->create(['code' => 'D']);
    Group::factory()->create(['subject_id' => $this->subject->id, 'code' => 'D']);
    assignCodeSession($this->group, 1, '10:00', '12:00');

    expect(assignCodeOf($this->group))->toBe('D');
});

it('un grupo cancelado no retiene su letra', function () {
    Group::factory()->cancelled()->create(['subject_id' => $this->subject->id, 'term_id' => $this->group->term_id, 'code' => 'D']);
    assignCodeSession($this->group, 1, '10:00', '12:00');

    expect(assignCodeOf($this->group))->toBe('D');
});

it('sin sesiones vuelve a una clave provisional', function () {
    $this->group->update(['code' => 'D']);
    Group::factory()->create(['subject_id' => $this->subject->id, 'term_id' => $this->group->term_id, 'code' => '1']);

    expect(assignCodeOf($this->group))->toBe('2');
});

it('si la clave no cambia no escribe ni registra', function () {
    $this->group->update(['code' => 'A']);
    assignCodeSession($this->group, 1, '07:00', '09:00');
    $updatedAt = $this->group->fresh()->updated_at;

    $this->travel(1)->minutes();
    assignCodeOf($this->group);

    expect($this->group->fresh()->updated_at->equalTo($updatedAt))->toBeTrue()
        ->and(Activity::query()->count())->toBe(0);
});

it('con la carga del departamento publicada no cambia la clave', function () {
    AcademicLoad::factory()->create([
        'term_id' => $this->group->term_id,
        'campus_id' => $this->group->campus_id,
        'department_id' => $this->group->department_id,
        'status' => LoadStatus::Published,
    ]);
    assignCodeSession($this->group, 1, '10:00', '12:00');

    expect(assignCodeOf($this->group))->toBe('1')
        ->and(Activity::query()->count())->toBe(0);
});

it('la carga publicada de otro departamento no frena el cambio', function () {
    AcademicLoad::factory()->create([
        'term_id' => $this->group->term_id,
        'campus_id' => $this->group->campus_id,
        'department_id' => Department::factory()->create()->id,
        'status' => LoadStatus::Published,
    ]);
    assignCodeSession($this->group, 1, '10:00', '12:00');

    expect(assignCodeOf($this->group))->toBe('D');
});

it('un grupo cancelado no cambia la clave', function () {
    $group = Group::factory()->cancelled()->create(['code' => 'X1']);
    assignCodeSession($group, 1, '10:00', '12:00');

    expect(assignCodeOf($group))->toBe('X1')
        ->and(Activity::query()->count())->toBe(0);
});

it('el listener recalcula la clave cuando cambian las sesiones del grupo', function () {
    assignCodeSession($this->group, 1, '08:00', '10:00');

    GroupScheduleChanged::dispatch($this->group);

    expect($this->group->fresh()->code)->toBe('B');
});
