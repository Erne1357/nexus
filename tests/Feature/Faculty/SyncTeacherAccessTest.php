<?php

use App\Modules\Academic\Actions\AppointDepartmentHead;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/*
| SyncTeacherAccess (spec 4.1): todo docente activo tiene usuario (username = número de empleado, contraseña por
| defecto y cambio obligatorio) con el rol `docente` y un alcance por cada campus donde tiene una adscripción
| (S14, supuesto por confirmar); reutiliza un usuario existente con su número sin tocar su contraseña, salvo que
| esté ligado a otro docente (S15, supuesto por confirmar). La baja desactiva su usuario, salvo admin, y termina
| sus jefaturas vigentes; reactivarlo lo devuelve (confirmado el 30 de octubre de 2026). Es idempotente.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ind = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
});

function syncAccess(Teacher $teacher): AccessSync
{
    return app(SyncTeacherAccess::class)->handle($teacher);
}

/**
 * Los campus de los alcances de `docente` vigentes o por empezar del usuario, por código.
 *
 * @return list<string>
 */
function teachingCampuses(User $user): array
{
    return $user->roleScopes()
        ->where('role', Role::Docente)
        ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()))
        ->with('campus')
        ->get()
        ->map(fn (RoleScope $scope): string => $scope->campus->code)
        ->sort()
        ->values()
        ->all();
}

it('da usuario a un docente activo sin usuario: su número, la contraseña por defecto y cambio obligatorio', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create([
        'employee_number' => '1234567',
        'name' => 'Ana Pérez',
        'email' => 'ana@example.test',
    ]);

    expect(syncAccess($teacher))->toBe(AccessSync::Created);

    $user = $teacher->fresh()->user;
    $context = app(ScopedContext::class)->forUser($user);
    $scope = $user->roleScopes()->sole();

    expect($user)
        ->username->toBe('1234567')
        ->name->toBe('Ana Pérez')
        ->email->toBe('ana@example.test')
        ->is_active->toBeTrue()
        ->must_change_password->toBeTrue()
        ->and(Hash::check(app(DefaultPassword::class)->value(), $user->password))->toBeTrue()
        ->and($user->hasRole(Role::Docente->value))->toBeTrue()
        ->and($scope)
        ->role->toBe(Role::Docente)
        ->campus_id->toBe($this->c2->id)
        ->department_id->toBeNull()
        ->career_id->toBeNull()
        ->valid_to->toBeNull()
        ->and($scope->valid_from->toDateString())->toBe(today()->toDateString())
        ->and($context->has(Role::Docente))->toBeTrue()
        ->and($context->canAccessCampus($this->c2))->toBeTrue()
        ->and($context->canAccessCampus($this->c1))->toBeFalse();
});

it('deja en la bitácora el alta del usuario, la liga con el docente y su acceso, sin la contraseña', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567']);

    syncAccess($teacher);

    $user = $teacher->fresh()->user;
    $created = Activity::where('subject_id', $user->id)->where('event', 'created')->sole();
    $linked = Activity::where('subject_id', $teacher->id)->sole();
    $access = Activity::where('subject_id', $user->id)->where('event', 'updated')->sole();
    $everything = Activity::all()->toJson();

    expect(Activity::count())->toBe(3)
        ->and($created->description)->toBe('Dio de alta al usuario 1234567')
        ->and($linked->event)->toBe('updated')
        ->and($linked->description)->toBe('Ligó al docente 1234567 con el usuario 1234567')
        ->and($linked->attribute_changes->all())->toBe(['attributes' => ['user_id' => $user->id], 'old' => ['user_id' => null]])
        ->and($access->description)->toBe('Sincronizó el acceso del docente 1234567: le dio el rol Docente y abrió su alcance de Docente en C2')
        ->and($access->attribute_changes->all())->toBe([
            'attributes' => ['roles' => ['docente'], 'docente_campus_ids' => [$this->c2->id]],
            'old' => ['roles' => [], 'docente_campus_ids' => []],
        ])
        ->and($everything)->not->toContain(app(DefaultPassword::class)->value())
        ->and($everything)->not->toContain($user->password);
});

it('no le da usuario a un docente inactivo', function () {
    $teacher = Teacher::factory()->inactive()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and($teacher->fresh()->user_id)->toBeNull()
        ->and(User::where('username', '1234567')->exists())->toBeFalse()
        ->and(Activity::count())->toBe(0);
});

it('correrlo dos veces da unchanged y no escribe bitácora', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c1)->adscribedTo($this->ind, $this->c2, false)->create();
    syncAccess($teacher);
    $entries = Activity::count();
    $scopes = RoleScope::count();

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged)
        ->and(Activity::count())->toBe($entries)
        ->and(RoleScope::count())->toBe($scopes);
});

it('liga un usuario existente con su número sin cambiar su contraseña', function () {
    $user = User::factory()->create(['username' => '1234567', 'must_change_password' => false]);
    $password = $user->password;
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Linked);

    expect($teacher->fresh()->user_id)->toBe($user->id)
        ->and($user->fresh()->password)->toBe($password)
        ->and($user->fresh()->must_change_password)->toBeFalse()
        ->and($user->fresh()->hasRole(Role::Docente->value))->toBeTrue()
        ->and(teachingCampuses($user))->toBe(['C2'])
        ->and(User::count())->toBe(1);
});

it('un usuario con su número ligado a otro docente da conflict y no toca nada', function () {
    $user = User::factory()->create(['username' => '1234567', 'is_active' => false]);
    Teacher::factory()->create(['user_id' => $user->id])->delete();
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Conflict);

    expect($teacher->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->roles)->toBeEmpty()
        ->and(RoleScope::count())->toBe(0)
        ->and(User::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});

it('con adscripciones en C1 y C2 tiene dos alcances de docente, y al quitar la de C1 se cierra ese', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c1)->adscribedTo($this->ind, $this->c2, false)->create(['employee_number' => '1234567']);
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    RoleScope::query()->update(['valid_from' => today()->subMonth()->toDateString()]);

    expect(teachingCampuses($user))->toBe(['C1', 'C2']);

    TeacherDepartment::where('campus_id', $this->c1->id)->sole()->delete();

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Updated);

    $closed = RoleScope::where('campus_id', $this->c1->id)->sole();
    $access = Activity::where('subject_id', $user->id)->where('event', 'updated')->latest('id')->first();

    expect($closed->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and(teachingCampuses($user))->toBe(['C2'])
        ->and(app(ScopedContext::class)->forUser($user)->canAccessCampus($this->c1))->toBeFalse()
        ->and(app(ScopedContext::class)->forUser($user)->canAccessCampus($this->c2))->toBeTrue()
        ->and($access->description)->toBe('Sincronizó el acceso del docente 1234567: cerró su alcance de Docente en C1')
        ->and($access->attribute_changes->all())->toBe([
            'attributes' => ['docente_campus_ids' => [$this->c2->id]],
            'old' => ['docente_campus_ids' => collect([$this->c1->id, $this->c2->id])->sort()->values()->all()],
        ]);
});

it('borra el alcance de un campus que se quita el mismo día que empezó', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c1)->adscribedTo($this->ind, $this->c2, false)->create();
    syncAccess($teacher);

    TeacherDepartment::where('campus_id', $this->c1->id)->sole()->delete();
    syncAccess($teacher->fresh());

    expect(RoleScope::where('campus_id', $this->c1->id)->exists())->toBeFalse()
        ->and(teachingCampuses($teacher->fresh()->user))->toBe(['C2']);
});

it('dar de baja desactiva su usuario y termina su jefatura vigente', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567']);
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $head = app(AppointDepartmentHead::class)->handle($this->sis, $this->c2, $user, DepartmentHeadKind::Jefe, today()->subMonth()->toImmutable());
    $past = DepartmentHead::factory()->projectLead()->create([
        'department_id' => $this->ind->id,
        'campus_id' => $this->c2->id,
        'user_id' => $user->id,
        'valid_from' => today()->subYear()->toDateString(),
        'valid_to' => today()->subMonths(6)->toDateString(),
    ]);

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    $yesterday = today()->subDay()->toDateString();
    $deactivated = Activity::where('subject_id', $user->id)->where('description', 'like', '%desactivó%')->sole();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and($head->fresh()->valid_to->toDateString())->toBe($yesterday)
        ->and(RoleScope::where('role', Role::JefeDepartamento)->sole()->valid_to->toDateString())->toBe($yesterday)
        ->and($past->fresh()->valid_to->toDateString())->toBe(today()->subMonths(6)->toDateString())
        ->and(app(ScopedContext::class)->forUser($user->fresh())->roles())->toBe([])
        ->and($deactivated->description)->toBe('Sincronizó el acceso del docente 1234567: desactivó su usuario')
        ->and($deactivated->attribute_changes->all())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged);
});

it('un docente que también es admin queda activo al darlo de baja, pero su jefatura termina', function () {
    $user = User::factory()->admin()->create(['username' => '1234567']);
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567', 'user_id' => $user->id]);
    $head = app(AppointDepartmentHead::class)->handle($this->sis, $this->c2, $user, DepartmentHeadKind::Jefe, today()->subMonth()->toImmutable());

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    expect($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->hasRole(Role::Admin->value))->toBeTrue()
        ->and($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged);
});

it('reactivar al docente reactiva su usuario sin tocar su contraseña', function () {
    $user = User::factory()->create(['username' => '1234567', 'is_active' => false]);
    $password = $user->password;
    $teacher = Teacher::factory()->inactive()->adscribedTo($this->sis, $this->c2)->create(['employee_number' => '1234567', 'user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and($user->fresh()->is_active)->toBeFalse();

    $teacher->update(['is_active' => true]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->password)->toBe($password)
        ->and(teachingCampuses($user))->toBe(['C2']);
});

it('un docente borrado cuenta como dado de baja', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis, $this->c2)->create();
    syncAccess($teacher);
    $user = $teacher->fresh()->user;

    $teacher->delete();

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and($user->fresh()->is_active)->toBeFalse();
});

it('no le pone a la cuenta nueva un correo que ya usa otro usuario', function () {
    User::factory()->create(['email' => 'ana@example.test']);
    $teacher = Teacher::factory()->create(['email' => 'ana@example.test']);

    expect(syncAccess($teacher))->toBe(AccessSync::Created)
        ->and($teacher->fresh()->user->email)->toBeNull()
        ->and($teacher->fresh()->email)->toBe('ana@example.test');
});

it('usa el hash de contraseña que se le da', function () {
    $hash = Hash::make(app(DefaultPassword::class)->value());
    $teacher = Teacher::factory()->create();

    app(SyncTeacherAccess::class)->handle($teacher, $hash);

    expect($teacher->fresh()->user->password)->toBe($hash);
});
