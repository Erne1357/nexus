<?php

use App\Modules\AcademicLoad\Actions\Transitions\ApproveLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| ApproveLoad da el visto bueno de Subdirección a una carga cerrada (`closed` → `approved`, tarea 4.2), solo en un
| campus con `requires_subdireccion_approval`. Regresarla con comentarios es ReopenLoad desde cerrada.
*/

function approvalLoad(bool $requiresApproval, LoadStatus $status = LoadStatus::Closed): AcademicLoad
{
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'requires_subdireccion_approval' => $requiresApproval]]);

    return AcademicLoad::factory()->inStatus($status)->create(['campus_id' => $campus->id]);
}

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('aprueba la carga cerrada con quién y cuándo, su historial y bitácora', function () {
    $this->freezeTime();
    $load = approvalLoad(true);

    app(ApproveLoad::class)->handle($load, $this->user, '  Todo en orden  ');

    $load = $load->fresh();
    expect($load->status)->toBe(LoadStatus::Approved)
        ->and($load->approved_by)->toBe($this->user->id)
        ->and($load->approved_at->toDateTimeString())->toBe(now()->toDateTimeString());

    $row = LoadTransition::query()->sole();
    expect($row->from_status)->toBe(LoadStatus::Closed)
        ->and($row->to_status)->toBe(LoadStatus::Approved)
        ->and($row->comment)->toBe('Todo en orden');
    expect(Activity::query()->where('event', 'transitioned')->sole()->properties['comment'])->toBe('Todo en orden');
});

it('el comentario es opcional', function () {
    $load = approvalLoad(true);

    app(ApproveLoad::class)->handle($load, $this->user);

    expect(LoadTransition::query()->sole()->comment)->toBeNull();
});

it('en un campus que no exige el visto bueno no puede pasar a Aprobada', function () {
    $load = approvalLoad(false);

    expect(fn () => app(ApproveLoad::class)->handle($load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['La carga está en Cerrada: no puede pasar a Aprobada.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Closed)
        ->and($load->fresh()->approved_by)->toBeNull()
        ->and(LoadTransition::query()->count())->toBe(0);
});

it('solo se aprueba una carga cerrada', function (LoadStatus $status) {
    $load = approvalLoad(true, $status);

    expect(fn () => app(ApproveLoad::class)->handle($load, $this->user))->toThrow(ValidationException::class);
})->with([LoadStatus::Staffing, LoadStatus::Adjusting, LoadStatus::Approved, LoadStatus::Published]);

it('regresar con comentarios es reabrir la carga cerrada', function () {
    $load = approvalLoad(true);

    app(ReopenLoad::class)->handle($load, $this->user, 'Falta el grupo vespertino');

    expect($load->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(LoadTransition::query()->sole()->comment)->toBe('Falta el grupo vespertino');
});

it('la policy deja aprobar al admin y a Subdirección, y niega a los demás', function (?Role $role, bool $expected) {
    $campus = Campus::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['campus_id' => $campus->id, 'department_id' => departmentKey(10)]);
    $user = $role === null
        ? User::factory()->admin()->create()
        : User::factory()->withRole($role, [
            'campus_id' => $campus->id,
            ...match ($role) {
                Role::Subdireccion => [],
                Role::Coordinador => ['career_id' => careerKey(20)],
                default => ['department_id' => departmentKey(10)],
            },
        ])->create();

    expect($user->can('approve', $load))->toBe($expected);
})->with([
    'admin' => [null, true],
    'subdirección' => [Role::Subdireccion, true],
    'jefe del departamento' => [Role::JefeDepartamento, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, false],
    'coordinador' => [Role::Coordinador, false],
]);
