<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Actions\JustifyGroup;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| La justificación de HC-15 (P2, confirmado el 10 de noviembre de 2026): el jefe la escribe por grupo, para el que se
| queda sin docente o sin salón, y queda con quién y cuándo. Se escribe mientras la carga se edita y la justifica
| quien cierra la carga.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Staffing, 'department_id' => departmentKey(10)]);
    $this->group = Group::factory()->create([
        'term_id' => $this->load->term_id,
        'campus_id' => $this->load->campus_id,
        'subject_id' => Subject::factory()->create(['department_id' => departmentKey(10)])->id,
    ]);
});

it('guarda la justificación con quién y cuándo, y deja bitácora', function () {
    $this->freezeTime();

    app(JustifyGroup::class)->handle($this->group, '  Docente de nuevo ingreso, contratación en trámite  ', $this->user);

    $group = $this->group->fresh();
    expect($group->close_justification)->toBe('Docente de nuevo ingreso, contratación en trámite')
        ->and($group->close_justified_by)->toBe($this->user->id)
        ->and($group->close_justified_at->toDateTimeString())->toBe(now()->toDateTimeString());

    expect(Activity::query()->where('event', 'justified')->sole()->subject_id)->toBe($this->group->id);
});

it('la quita con un texto vacío', function () {
    $this->group->forceFill(['close_justification' => 'Contratación en trámite', 'close_justified_by' => $this->user->id, 'close_justified_at' => now()])->save();

    app(JustifyGroup::class)->handle($this->group, '  ', $this->user);

    expect($this->group->fresh()->only(['close_justification', 'close_justified_by', 'close_justified_at']))
        ->toBe(['close_justification' => null, 'close_justified_by' => null, 'close_justified_at' => null]);
});

it('no justifica un grupo que ya tiene docente y salón en cada sesión presencial', function () {
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->load->campus_id])->id]);
    $this->group->update(['teacher_id' => Teacher::factory()->create()->id]);
    GroupSession::factory()->for($this->group)->inRoom($room)->on(1, '07:00', '09:00')->create();

    expect(fn () => app(JustifyGroup::class)->handle($this->group, 'Por si acaso', $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['justification' => ['El grupo ya tiene docente y salón: no necesita justificación.']]),
    );
});

it('no se escribe con la carga cerrada', function () {
    $this->load->update(['status' => LoadStatus::Closed]);

    expect(fn () => app(JustifyGroup::class)->handle($this->group, 'Contratación en trámite', $this->user))->toThrow(ValidationException::class);

    expect($this->group->fresh()->close_justification)->toBeNull();
});

it('la policy deja justificar a quien cierra la carga del departamento', function (?Role $role, int $department, bool $expected) {
    $user = $role === null
        ? User::factory()->admin()->create()
        : User::factory()->withRole($role, [
            'campus_id' => $this->load->campus_id,
            ...($role === Role::Coordinador ? ['career_id' => $this->group->owner_career_id] : ($role === Role::Subdireccion ? [] : ['department_id' => departmentKey($department)])),
        ])->create();

    expect($user->can('justify', $this->group))->toBe($expected);
})->with([
    'admin' => [null, 10, true],
    'jefe del departamento' => [Role::JefeDepartamento, 10, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, 11, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, 10, false],
    'coordinador de la carrera' => [Role::Coordinador, 10, false],
    'subdirección' => [Role::Subdireccion, 10, false],
]);
