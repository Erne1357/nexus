<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Core\Actions\AppointRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\DefaultPassword;
use App\Modules\Faculty\Actions\SyncTeacherAccess;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Models\Activity;

/*
| SyncTeacherAccess (spec 4.1): todo docente activo tiene usuario (username = número de empleado, contraseña por
| defecto y cambio obligatorio) con el rol `docente`. El docente no es de un campus (confirmado el 30 de octubre de
| 2026); de ahí sale que su alcance sea uno solo, sin campus, tenga o no departamento, y que se cierren los
| alcances de `docente` por campus que quedaron de antes. Reutiliza un usuario existente con su número sin tocar su contraseña, salvo que esté ligado
| a otro docente (S15, supuesto por confirmar). La baja desactiva su usuario, salvo admin, y termina sus jefaturas
| vigentes; reactivarlo lo devuelve (confirmado el 30 de octubre de 2026). Es idempotente.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ind = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
});

/**
 * Nombra a `$user` jefatura del departamento con AppointRole, del día `$from` (y hasta `$to`, si lo hay).
 */
function appointHeadship(User $user, Department $department, Role $role, string $from, ?Campus $campus = null, ?string $to = null): RoleAssignment
{
    return app(AppointRole::class)->handle(
        $user,
        $role,
        $campus,
        OrgUnit::query()->findOrFail($department->id),
        null,
        CarbonImmutable::parse($from),
        $to === null ? null : CarbonImmutable::parse($to),
    );
}

function syncAccess(Teacher $teacher): AccessSync
{
    return app(SyncTeacherAccess::class)->handle($teacher);
}

/**
 * Los alcances de `docente` vigentes o por empezar del usuario: el código de su campus o, sin campus, «todo el Tec».
 *
 * @return list<string>
 */
function teachingScopes(User $user): array
{
    return $user->roleAssignments()
        ->where('role', Role::Docente)
        ->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', today()->toDateString()))
        ->with('campus')
        ->get()
        ->map(fn (RoleAssignment $scope): string => $scope->campus?->code ?? 'todo el Tec')
        ->sort()
        ->values()
        ->all();
}

it('da usuario a un docente activo sin usuario: su número, la contraseña por defecto y cambio obligatorio', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create([
        'employee_number' => '1234567',
        'name' => 'Ana Pérez',
        'email' => 'ana@example.test',
    ]);

    expect(syncAccess($teacher))->toBe(AccessSync::Created);

    $user = $teacher->fresh()->user;
    $context = app(Actor::class)->forUser($user);
    $scope = $user->roleAssignments()->sole();

    expect($user)
        ->username->toBe('1234567')
        ->name->toBe('Ana Pérez')
        ->email->toBe('ana@example.test')
        ->is_active->toBeTrue()
        ->must_change_password->toBeTrue()
        ->and(Hash::check(app(DefaultPassword::class)->value(), $user->password))->toBeTrue()
        ->and($user->roleAssignments()->current()->where('role', Role::Docente)->exists())->toBeTrue()
        ->and($scope)
        ->role->toBe(Role::Docente)
        ->campus_id->toBeNull()
        ->org_unit_id->toBeNull()
        ->career_id->toBeNull()
        ->valid_to->toBeNull()
        ->and($scope->valid_from->toDateString())->toBe(today()->toDateString())
        ->and($context->has(Role::Docente))->toBeTrue()
        ->and($context->canAccessCampus($this->c1))->toBeTrue()
        ->and($context->canAccessCampus($this->c2))->toBeTrue();
});

it('deja en la bitácora el alta del usuario, la liga con el docente y su acceso, sin la contraseña', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

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
        ->and($access->description)->toBe('Sincronizó el acceso del docente 1234567: abrió su alcance de Docente en todo el Tec')
        ->and($access->attribute_changes->all())->toBe([
            'attributes' => ['docente_general' => true],
            'old' => ['docente_general' => false],
        ])
        ->and($everything)->not->toContain(app(DefaultPassword::class)->value())
        ->and($everything)->not->toContain($user->password);
});

it('no le da usuario a un docente inactivo', function () {
    $teacher = Teacher::factory()->inactive()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and($teacher->fresh()->user_id)->toBeNull()
        ->and(User::where('username', '1234567')->exists())->toBeFalse()
        ->and(Activity::count())->toBe(0);
});

it('correrlo dos veces da unchanged y no escribe bitácora', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ind, false)->create();
    syncAccess($teacher);
    $entries = Activity::count();
    $scopes = RoleAssignment::count();

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged)
        ->and(Activity::count())->toBe($entries)
        ->and(RoleAssignment::count())->toBe($scopes);
});

it('liga un usuario existente con su número sin cambiar su contraseña', function () {
    $user = User::factory()->create(['username' => '1234567', 'must_change_password' => false]);
    $password = $user->password;
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Linked);

    expect($teacher->fresh()->user_id)->toBe($user->id)
        ->and($user->fresh()->password)->toBe($password)
        ->and($user->fresh()->must_change_password)->toBeFalse()
        ->and($user->fresh()->roleAssignments()->current()->where('role', Role::Docente)->exists())->toBeTrue()
        ->and(teachingScopes($user))->toBe(['todo el Tec'])
        ->and(User::count())->toBe(1);
});

it('un usuario con su número ligado a otro docente da conflict y no toca nada', function () {
    $user = User::factory()->create(['username' => '1234567', 'is_active' => false]);
    Teacher::factory()->create(['user_id' => $user->id])->delete();
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Conflict);

    expect($teacher->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->roles)->toBeEmpty()
        ->and(RoleAssignment::count())->toBe(0)
        ->and(User::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});

it('adscrito a dos departamentos tiene un solo alcance de docente, sin campus', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->adscribedTo($this->ind, false)->create();

    syncAccess($teacher);

    expect(teachingScopes($teacher->fresh()->user))->toBe(['todo el Tec'])
        ->and(RoleAssignment::where('role', Role::Docente)->count())->toBe(1);
});

it('cierra los alcances de docente por campus que quedaron de antes y abre el de todo el Tec', function () {
    $user = User::factory()
        ->withRole(Role::Docente, ['campus_id' => $this->c1->id])
        ->withRole(Role::Docente, ['campus_id' => $this->c2->id])
        ->create(['username' => '1234567']);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567', 'user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    $yesterday = today()->subDay()->toDateString();
    $access = Activity::where('subject_id', $user->id)->sole();
    $context = app(Actor::class)->forUser($user->fresh());

    expect(RoleAssignment::whereNotNull('campus_id')->pluck('valid_to')->map->toDateString()->all())->toBe([$yesterday, $yesterday])
        ->and(teachingScopes($user))->toBe(['todo el Tec'])
        ->and($context->canAccessCampus($this->c1))->toBeTrue()
        ->and($context->canAccessCampus($this->c2))->toBeTrue()
        ->and($access->description)->toBe('Sincronizó el acceso del docente 1234567: abrió su alcance de Docente en todo el Tec y cerró su alcance de Docente en C1 y C2')
        ->and($access->attribute_changes->all())->toBe([
            'attributes' => ['docente_general' => true, 'docente_campus_ids' => []],
            'old' => ['docente_general' => false, 'docente_campus_ids' => collect([$this->c1->id, $this->c2->id])->sort()->values()->all()],
        ]);

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged)
        ->and(Activity::count())->toBe(1);
});

it('con el alcance de todo el Tec y uno por campus que quedó de antes, solo cierra el del campus', function () {
    $user = User::factory()
        ->withRole(Role::Docente)
        ->withRole(Role::Docente, ['campus_id' => $this->c1->id])
        ->create(['username' => '1234567']);
    $general = RoleAssignment::query()->whereNull('campus_id')->sole();
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567', 'user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    $access = Activity::where('subject_id', $user->id)->sole();

    expect(RoleAssignment::where('campus_id', $this->c1->id)->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($general->fresh()->valid_to)->toBeNull()
        ->and(RoleAssignment::where('role', Role::Docente)->count())->toBe(2)
        ->and(teachingScopes($user))->toBe(['todo el Tec'])
        ->and($access->description)->toBe('Sincronizó el acceso del docente 1234567: cerró su alcance de Docente en C1')
        ->and($access->attribute_changes->all())->toBe([
            'attributes' => ['docente_campus_ids' => []],
            'old' => ['docente_campus_ids' => [$this->c1->id]],
        ]);
});

it('un alcance de todo el Tec que todavía no empieza cuenta como el suyo y no abre otro (supuesto por confirmar)', function () {
    $user = User::factory()->withRole(Role::Docente, ['valid_from' => today()->addDays(3)->toDateString()])->create(['username' => '1234567']);
    $teacher = Teacher::factory()->create(['employee_number' => '1234567', 'user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and(RoleAssignment::count())->toBe(1)
        ->and(Activity::count())->toBe(0);
});

it('borra el alcance de docente por campus que empezó hoy', function () {
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c1->id, 'valid_from' => today()->toDateString()])->create();
    $teacher = Teacher::factory()->create(['user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and(RoleAssignment::whereNotNull('campus_id')->exists())->toBeFalse()
        ->and(teachingScopes($user))->toBe(['todo el Tec']);
});

it('un docente activo sin departamento conserva su usuario, su rol y su alcance', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $entries = Activity::count();

    TeacherDepartment::query()->where('teacher_id', $teacher->id)->sole()->delete();

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->roleAssignments()->current()->where('role', Role::Docente)->exists())->toBeTrue()
        ->and(teachingScopes($user))->toBe(['todo el Tec'])
        ->and(app(Actor::class)->forUser($user->fresh())->has(Role::Docente))->toBeTrue()
        ->and(Activity::count())->toBe($entries);
});

it('a un docente nuevo sin departamento le da usuario, rol y alcance', function () {
    $teacher = Teacher::factory()->create();

    expect(syncAccess($teacher))->toBe(AccessSync::Created)
        ->and(teachingScopes($teacher->fresh()->user))->toBe(['todo el Tec']);
});

it('dar de baja desactiva su usuario y termina su jefatura vigente', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $head = appointHeadship($user, $this->sis, Role::JefeDepartamento, '2026-10-03');
    $past = RoleAssignment::factory()->for($user)->create([
        'role' => Role::JefeProyectoDocente,
        'org_unit_id' => $this->ind->id,
        'campus_id' => $this->c2->id,
        'valid_from' => '2025-11-03',
        'valid_to' => '2026-05-03',
    ]);

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    $deactivated = Activity::where('subject_id', $user->id)->where('description', 'like', '%desactivó%')->sole();

    expect($user->fresh()->is_active)->toBeFalse()
        ->and($head->fresh()->valid_to->toDateString())->toBe('2026-11-02')
        ->and($past->fresh()->valid_to->toDateString())->toBe('2026-05-03')
        ->and(app(Actor::class)->forUser($user->fresh())->heldRoles())->toBe([])
        ->and($deactivated->description)->toBe('Sincronizó el acceso del docente 1234567: desactivó su usuario')
        ->and($deactivated->attribute_changes->all())->toBe(['attributes' => ['is_active' => false], 'old' => ['is_active' => true]]);

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged);
});

it('la baja quita la jefatura que empieza hoy', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $head = appointHeadship($user, $this->sis, Role::JefeDepartamento, '2026-11-03');

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and(RoleAssignment::query()->whereKey($head->id)->exists())->toBeFalse()
        ->and($user->fresh()->is_active)->toBeFalse();
});

it('la baja no termina los puestos que no son jefaturas', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $coordinator = RoleAssignment::factory()->for($user)->create(['role' => Role::Coordinador, 'campus_id' => $this->c2->id, 'career_id' => Career::factory()->create()->id, 'valid_from' => '2026-09-01', 'valid_to' => null]);

    $teacher->update(['is_active' => false]);
    syncAccess($teacher);

    expect($coordinator->fresh()->valid_to)->toBeNull();
});

it('la baja de un docente sin departamento desactiva su usuario', function () {
    $teacher = Teacher::factory()->create(['employee_number' => '1234567']);
    syncAccess($teacher);

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and($teacher->fresh()->user->is_active)->toBeFalse();
});

it('un docente que también es admin queda activo al darlo de baja, pero su jefatura termina', function () {
    $user = User::factory()->admin()->create(['username' => '1234567']);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567', 'user_id' => $user->id]);
    $head = appointHeadship($user, $this->sis, Role::JefeDepartamento, today()->subMonth()->toDateString());

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated);

    expect($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->roleAssignments()->current()->where('role', Role::Admin)->exists())->toBeTrue()
        ->and($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString());

    expect(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged);
});

/**
 * Un docente de baja cuyo usuario tiene Administración programada desde `$from`, y Ana (admin) hasta `$anaTo`.
 */
function scheduledAdminTeacher(string $from, string $anaTo, bool $withBeto): Teacher
{
    $user = User::factory()->create(['username' => '1234567']);
    RoleAssignment::factory()->create(['user_id' => $user->id, 'role' => Role::Admin, 'valid_from' => $from, 'valid_to' => null]);
    RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::Admin, 'valid_from' => '2026-08-01', 'valid_to' => $anaTo]);

    if ($withBeto) {
        RoleAssignment::factory()->create(['user_id' => User::factory()->create()->id, 'role' => Role::Admin, 'valid_from' => '2026-01-01', 'valid_to' => null]);
    }

    return Teacher::factory()->inactive()->create(['employee_number' => '1234567', 'user_id' => $user->id]);
}

it('P3: el docente queda dado de baja pero su usuario sigue activo si se necesita como admin', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = scheduledAdminTeacher('2026-12-01', '2026-11-30', withBeto: false);
    $before = Activity::query()->count();

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and($teacher->user->fresh()->is_active)->toBeTrue()
        ->and(Activity::query()->count())->toBe($before)
        ->and(syncAccess($teacher->fresh()))->toBe(AccessSync::Unchanged)
        ->and($teacher->user->fresh()->is_active)->toBeTrue();
});

it('AZ-S12: la baja sí desactiva al usuario con Administración programada si otro admin cubre los días que siguen', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = scheduledAdminTeacher('2026-12-01', '2026-11-30', withBeto: true);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and($teacher->user->fresh()->is_active)->toBeFalse();
});

it('AZ-S12: la baja toma el candado de los puestos de Administración antes que el del usuario', function () {
    $this->travelTo('2026-11-03 10:00:00');
    $teacher = scheduledAdminTeacher('2026-12-01', '2026-11-30', withBeto: true);
    $statements = [];
    DB::listen(function ($query) use (&$statements): void {
        $statements[] = strtolower($query->sql);
    });

    syncAccess($teacher);

    $lock = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'pg_advisory_xact_lock'));
    $userRow = collect($statements)->search(fn (string $sql): bool => str_contains($sql, 'from "users"') && str_contains($sql, 'for update'));

    expect($lock)->not->toBeFalse()
        ->and($userRow)->not->toBeFalse()
        ->and($lock)->toBeLessThan($userRow);
});

it('reactivar al docente reactiva su usuario sin tocar su contraseña', function () {
    $user = User::factory()->create(['username' => '1234567', 'is_active' => false]);
    $password = $user->password;
    $teacher = Teacher::factory()->inactive()->adscribedTo($this->sis)->create(['employee_number' => '1234567', 'user_id' => $user->id]);

    expect(syncAccess($teacher))->toBe(AccessSync::Unchanged)
        ->and($user->fresh()->is_active)->toBeFalse();

    $teacher->update(['is_active' => true]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->password)->toBe($password)
        ->and(teachingScopes($user))->toBe(['todo el Tec']);
});

it('un docente borrado cuenta como dado de baja', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
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

it('no liga un usuario inactivo con su número: conflict y sigue inactivo', function () {
    $user = User::factory()->create(['username' => '1234567', 'is_active' => false]);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Conflict);

    expect($teacher->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->is_active)->toBeFalse()
        ->and($user->fresh()->roles)->toBeEmpty()
        ->and(RoleAssignment::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
});

it('no liga un usuario con su número que tiene otro rol: conflict y no lo toca', function (Role $role) {
    $scope = match ($role) {
        Role::Admin => [],
        Role::Subdireccion => ['campus_id' => $this->c2->id],
        default => ['campus_id' => $this->c2->id, 'department_id' => $this->sis->id],
    };
    $user = User::factory()->withRole($role, $scope)->create(['username' => '1234567']);
    $scopes = RoleAssignment::count();
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Conflict);

    expect($teacher->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue()
        ->and($user->fresh()->roleAssignments()->pluck('role')->unique()->values()->all())->toBe([$role])
        ->and(RoleAssignment::count())->toBe($scopes)
        ->and(Activity::count())->toBe(0);
})->with([
    'admin' => Role::Admin,
    'subdirección' => Role::Subdireccion,
    'jefe de departamento' => Role::JefeDepartamento,
]);

it('no liga la cuenta de quien fue personal aunque su nombramiento ya terminó', function () {
    $user = User::factory()->withRole(Role::JefeDepartamento, [
        'department_id' => $this->sis->id,
        'valid_from' => today()->subYear()->toDateString(),
        'valid_to' => today()->subMonth()->toDateString(),
    ])->create(['username' => '1234567']);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Conflict)
        ->and($teacher->fresh()->user_id)->toBeNull()
        ->and($user->fresh()->is_active)->toBeTrue();
});

it('liga un usuario activo con su número que solo tiene el rol docente', function () {
    $user = User::factory()->withRole(Role::Docente, ['campus_id' => $this->c2->id])->create(['username' => '1234567']);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create(['employee_number' => '1234567']);

    expect(syncAccess($teacher))->toBe(AccessSync::Linked)
        ->and($teacher->fresh()->user_id)->toBe($user->id)
        ->and(teachingScopes($user))->toBe(['todo el Tec']);
});

it('la baja borra también la jefatura que todavía no empieza, y al reactivarlo no vuelve', function () {
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    syncAccess($teacher);
    $user = $teacher->fresh()->user;
    $head = appointHeadship($user, $this->sis, Role::JefeDepartamento, today()->addDays(5)->toDateString());

    $teacher->update(['is_active' => false]);

    expect(syncAccess($teacher))->toBe(AccessSync::Updated)
        ->and(RoleAssignment::query()->whereKey($head->id)->exists())->toBeFalse()
        ->and(RoleAssignment::where('role', Role::JefeDepartamento)->exists())->toBeFalse();

    $teacher->update(['is_active' => true]);
    syncAccess($teacher);
    $this->travel(10)->days();

    $context = app(Actor::class)->forUser($user->fresh());

    expect($context->has(Role::JefeDepartamento))->toBeFalse()
        ->and($context->has(Role::Docente))->toBeTrue();
});

it('avisa con TeacherAccessChanged después del commit solo cuando cambia algo', function () {
    Event::fake([TeacherAccessChanged::class]);
    $teacher = Teacher::factory()->adscribedTo($this->sis)->create();
    $taken = User::factory()->create(['username' => '7654321', 'is_active' => false]);
    $conflicted = Teacher::factory()->create(['employee_number' => '7654321']);

    syncAccess($teacher);
    syncAccess($teacher->fresh());
    syncAccess($conflicted);

    Event::assertDispatchedTimes(TeacherAccessChanged::class, 1);
    Event::assertDispatched(TeacherAccessChanged::class, fn (TeacherAccessChanged $event): bool => $event->teacher->is($teacher) && $event->result === AccessSync::Created);
});
