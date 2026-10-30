<?php

use App\Modules\Academic\Actions\AppointDepartmentHead;
use App\Modules\Academic\Actions\EndDepartmentHead;
use App\Modules\Academic\Enums\DepartmentHeadKind;
use App\Modules\Academic\Events\DepartmentHeadAppointed;
use App\Modules\Academic\Events\DepartmentHeadEnded;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\DepartmentHead;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\ScopedContext;
use Carbon\CarbonImmutable;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Jefaturas (confirmado el 30 de octubre de 2026): nombrar a un jefe o a un jefe de proyecto docente de un
| departamento escribe, en la misma transacción, la fila de `department_heads`, el rol de spatie y un alcance con
| el mismo campus, departamento y fechas. Terminarla cierra las dos con la regla de CloseRoleScopes: la que ya
| empezó termina en el último día; la que empieza después, se borra. El jefe es de todo el Tec, sin campus
| (confirmado el 30 de octubre de 2026). El jefe de proyecto docente es de un campus (supuesto por confirmar).
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ind = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
    $this->user = User::factory()->create(['username' => '1234567', 'name' => 'Ana Pérez']);
});

/**
 * Nombra a `$user`: jefe del departamento si `$campus` es nulo; si no, jefe de proyecto docente en ese campus
 * (salvo que `$kind` diga otra cosa, para probar los rechazos).
 */
function appointHead(User $user, Department $department, ?Campus $campus = null, ?DepartmentHeadKind $kind = null, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): DepartmentHead
{
    $kind ??= $campus === null ? DepartmentHeadKind::Jefe : DepartmentHeadKind::JefeProyectoDocente;

    return app(AppointDepartmentHead::class)->handle($department, $campus, $user, $kind, $from ?? today()->subMonth()->toImmutable(), $to);
}

it('nombrar a un jefe le da la fila, el rol y un alcance de todo el Tec con las mismas fechas', function () {
    Event::fake([DepartmentHeadAppointed::class]);
    $from = today()->subDays(3)->toImmutable();
    $to = today()->addMonths(6)->toImmutable();

    $head = appointHead($this->user, $this->sis, null, DepartmentHeadKind::Jefe, $from, $to);

    $scope = RoleScope::sole();
    $context = app(ScopedContext::class)->forUser($this->user->fresh());

    expect($head->fresh())
        ->department_id->toBe($this->sis->id)
        ->campus_id->toBeNull()
        ->user_id->toBe($this->user->id)
        ->kind->toBe(DepartmentHeadKind::Jefe)
        ->and($head->fresh()->valid_from->toDateString())->toBe($from->toDateString())
        ->and($head->fresh()->valid_to->toDateString())->toBe($to->toDateString())
        ->and($this->user->fresh()->hasRole(Role::JefeDepartamento->value))->toBeTrue()
        ->and($scope)
        ->user_id->toBe($this->user->id)
        ->role->toBe(Role::JefeDepartamento)
        ->campus_id->toBeNull()
        ->department_id->toBe($this->sis->id)
        ->career_id->toBeNull()
        ->and($scope->valid_from->toDateString())->toBe($from->toDateString())
        ->and($scope->valid_to->toDateString())->toBe($to->toDateString())
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->sis->id))->toBeTrue()
        ->and($context->covers(Role::JefeDepartamento, $this->c1, $this->sis->id))->toBeTrue()
        ->and($context->coversDepartment(Role::JefeDepartamento, $this->sis->id))->toBeTrue()
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->ind->id))->toBeFalse()
        ->and($context->coversDepartment(Role::JefeDepartamento, $this->ind->id))->toBeFalse();

    Event::assertDispatched(DepartmentHeadAppointed::class, fn (DepartmentHeadAppointed $event): bool => $event->head->is($head));
});

it('deja en la bitácora del usuario a quién nombró, de qué y desde cuándo', function () {
    $head = appointHead($this->user, $this->sis, $this->c2);

    $entry = Activity::sole();

    expect($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->event)->toBe('created')
        ->and($entry->description)->toBe('Nombró a Ana Pérez jefe de proyecto docente de Sistemas y Computación en Campus II')
        ->and($entry->attribute_changes['attributes'])->toBe([
            'department_head_id' => $head->id,
            'kind' => 'jefe_proyecto_docente',
            'department_id' => $this->sis->id,
            'campus_id' => $this->c2->id,
            'valid_from' => today()->subMonth()->toDateString(),
            'valid_to' => null,
        ]);
});

it('la bitácora del jefe no nombra campus: es de todo el Tec', function () {
    appointHead($this->user, $this->sis);

    $entry = Activity::sole();

    expect($entry->description)->toBe('Nombró a Ana Pérez jefe de departamento de Sistemas y Computación')
        ->and($entry->attribute_changes['attributes'])->toMatchArray(['kind' => 'jefe', 'campus_id' => null]);
});

it('un jefe con campus es error de validación en campus y no escribe nada', function () {
    try {
        appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::Jefe);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['campus' => ['El jefe de departamento es de todo el Tec: no lleva campus.']]);
    }

    expect(DepartmentHead::count())->toBe(0)
        ->and(RoleScope::count())->toBe(0)
        ->and($this->user->fresh()->roles)->toBeEmpty()
        ->and(Activity::count())->toBe(0);
});

it('un jefe de proyecto docente sin campus es error de validación en campus y no escribe nada', function () {
    try {
        appointHead($this->user, $this->sis, null, DepartmentHeadKind::JefeProyectoDocente);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['campus' => ['Elige el campus del jefe de proyecto docente.']]);
    }

    expect(DepartmentHead::count())->toBe(0)
        ->and(RoleScope::count())->toBe(0)
        ->and($this->user->fresh()->roles)->toBeEmpty()
        ->and(Activity::count())->toBe(0);
});

it('un segundo jefe vigente del mismo departamento es error de validación en user', function () {
    appointHead($this->user, $this->sis);
    $other = User::factory()->create();

    try {
        appointHead($other, $this->sis, from: today()->toImmutable());
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['user' => ['Ya hay un jefe de Sistemas y Computación vigente en esas fechas.']]);
    }

    expect(DepartmentHead::count())->toBe(1)
        ->and($other->fresh()->roles)->toBeEmpty()
        ->and($other->roleScopes()->exists())->toBeFalse()
        ->and(Activity::count())->toBe(1);
});

it('otro departamento tiene su propio jefe, y el departamento su jefe de proyecto docente en cada campus', function () {
    appointHead($this->user, $this->sis);

    appointHead(User::factory()->create(), $this->ind);
    appointHead(User::factory()->create(), $this->sis, $this->c1);
    appointHead(User::factory()->create(), $this->sis, $this->c2);

    expect(DepartmentHead::count())->toBe(4);
});

it('dos jefes de proyecto docente vigentes a la vez sí', function () {
    $other = User::factory()->create();

    appointHead($this->user, $this->sis, $this->c2);
    appointHead($other, $this->sis, $this->c2);

    expect(DepartmentHead::count())->toBe(2)
        ->and(app(ScopedContext::class)->forUser($this->user)->covers(Role::JefeProyectoDocente, $this->c2, $this->sis->id))->toBeTrue()
        ->and(app(ScopedContext::class)->forUser($other)->covers(Role::JefeProyectoDocente, $this->c2, $this->sis->id))->toBeTrue()
        ->and(app(ScopedContext::class)->forUser($other)->covers(Role::JefeProyectoDocente, $this->c1, $this->sis->id))->toBeFalse()
        ->and(RoleScope::query()->pluck('campus_id')->unique()->all())->toBe([$this->c2->id]);
});

it('la misma persona no es dos veces jefe de proyecto docente en fechas que se traslapan', function () {
    appointHead($this->user, $this->sis, $this->c2);

    expect(fn () => appointHead($this->user, $this->sis, $this->c2, from: today()->toImmutable()))
        ->toThrow(ValidationException::class, 'Ana Pérez ya es jefe de proyecto docente de Sistemas y Computación en Campus II en esas fechas.');

    expect(DepartmentHead::count())->toBe(1)
        ->and(RoleScope::count())->toBe(1);
});

it('rechaza una jefatura que termina antes de empezar', function () {
    expect(fn () => appointHead($this->user, $this->sis, from: today()->toImmutable(), to: today()->subDay()->toImmutable()))
        ->toThrow(ValidationException::class, 'La fecha de fin no puede ser anterior a la de inicio.');

    expect(DepartmentHead::count())->toBe(0)
        ->and(RoleScope::count())->toBe(0);
});

it('recarga el contexto del usuario autenticado al nombrarlo', function () {
    $this->actingAs($this->user);

    expect(app(ScopedContext::class)->has(Role::JefeDepartamento))->toBeFalse();

    appointHead($this->user, $this->sis);

    expect(app(ScopedContext::class)->has(Role::JefeDepartamento))->toBeTrue();
});

it('terminar ayer una jefatura deja de cubrir hoy', function () {
    Event::fake([DepartmentHeadEnded::class]);
    $head = appointHead($this->user, $this->sis);
    $context = app(ScopedContext::class)->forUser($this->user);

    expect($context->coversDepartment(Role::JefeDepartamento, $this->sis->id))->toBeTrue();

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $yesterday = today()->subDay()->toDateString();

    expect($head->fresh()->valid_to->toDateString())->toBe($yesterday)
        ->and(RoleScope::sole()->valid_to->toDateString())->toBe($yesterday)
        ->and($context->coversDepartment(Role::JefeDepartamento, $this->sis->id))->toBeFalse();

    $entry = Activity::latest('id')->first();

    expect($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->event)->toBe('updated')
        ->and($entry->description)->toBe('Terminó la jefatura de Ana Pérez como jefe de departamento de Sistemas y Computación')
        ->and($entry->attribute_changes->all())->toBe([
            'attributes' => ['valid_to' => $yesterday],
            'old' => ['valid_to' => null],
        ]);

    Event::assertDispatched(DepartmentHeadEnded::class, fn (DepartmentHeadEnded $event): bool => $event->head->is($head));
});

it('terminar una jefatura que empieza mañana la borra con su alcance', function () {
    $head = appointHead($this->user, $this->sis, from: today()->addDay()->toImmutable());

    app(EndDepartmentHead::class)->handle($head, today()->toImmutable());

    $entry = Activity::latest('id')->first();

    expect(DepartmentHead::find($head->id))->toBeNull()
        ->and(DepartmentHead::withTrashed()->find($head->id)->trashed())->toBeTrue()
        ->and(RoleScope::count())->toBe(0)
        ->and($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->event)->toBe('deleted')
        ->and($entry->description)->toBe('Quitó la jefatura de Ana Pérez como jefe de departamento de Sistemas y Computación, que todavía no empezaba')
        ->and($entry->attribute_changes['old'])->toMatchArray([
            'department_head_id' => $head->id,
            'kind' => 'jefe',
            'valid_from' => today()->addDay()->toDateString(),
            'valid_to' => null,
        ]);
});

it('una jefatura terminada deja lugar a otro jefe desde el día siguiente', function () {
    $head = appointHead($this->user, $this->sis);
    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $next = appointHead(User::factory()->create(), $this->sis, from: today()->toImmutable());

    expect($next->exists)->toBeTrue();
});

it('no alarga una jefatura que ya termina antes del último día dado', function () {
    $end = today()->addDays(10)->toImmutable();
    $head = appointHead($this->user, $this->sis, to: $end);
    $entries = Activity::count();

    app(EndDepartmentHead::class)->handle($head, today()->addDays(20)->toImmutable());

    expect($head->fresh()->valid_to->toDateString())->toBe($end->toDateString())
        ->and(RoleScope::sole()->valid_to->toDateString())->toBe($end->toDateString())
        ->and(Activity::count())->toBe($entries);
});

it('termina el alcance de su jefatura y no los de otras', function () {
    $head = appointHead($this->user, $this->sis);
    appointHead($this->user, $this->ind);
    $projectLead = appointHead($this->user, $this->sis, $this->c1);
    appointHead($this->user, $this->sis, $this->c2);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());
    app(EndDepartmentHead::class)->handle($projectLead, today()->subDay()->toImmutable());

    $context = app(ScopedContext::class)->forUser($this->user);

    expect($context->coversDepartment(Role::JefeDepartamento, $this->sis->id))->toBeFalse()
        ->and($context->covers(Role::JefeDepartamento, $this->c1, $this->ind->id))->toBeTrue()
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->ind->id))->toBeTrue()
        ->and($context->covers(Role::JefeProyectoDocente, $this->c1, $this->sis->id))->toBeFalse()
        ->and($context->covers(Role::JefeProyectoDocente, $this->c2, $this->sis->id))->toBeTrue();
});

it('el jefe de proyecto docente que termina deja en la bitácora su campus', function () {
    $head = appointHead($this->user, $this->sis, $this->c2);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    expect(Activity::latest('id')->first()->description)
        ->toBe('Terminó la jefatura de Ana Pérez como jefe de proyecto docente de Sistemas y Computación en Campus II');
});

it('solo vuelve error de validación el rechazo de una exclusión (SQLSTATE 23P01); otro error de la base sigue igual', function () {
    DepartmentHead::creating(function (): never {
        $previous = new PDOException('SQLSTATE[23505]: Unique violation: department_heads_one_jefe');
        $previous->errorInfo = ['23505', 7, 'department_heads_one_jefe'];

        throw new QueryException('pgsql', 'insert into department_heads', [], $previous);
    });

    expect(fn () => appointHead($this->user, $this->sis))->toThrow(QueryException::class);

    expect(RoleScope::count())->toBe(0)
        ->and(Activity::count())->toBe(0);
});

it('termina antes de tiempo una jefatura que tenía fecha de fin, con su alcance', function () {
    $head = appointHead($this->user, $this->sis, to: today()->addMonths(2)->toImmutable());

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $yesterday = today()->subDay()->toDateString();

    expect($head->fresh()->valid_to->toDateString())->toBe($yesterday)
        ->and(RoleScope::sole()->valid_to->toDateString())->toBe($yesterday)
        ->and(app(ScopedContext::class)->forUser($this->user)->coversDepartment(Role::JefeDepartamento, $this->sis->id))->toBeFalse();
});

it('al terminar una jefatura solo cierra el alcance que empezó con ella', function () {
    $head = appointHead($this->user, $this->sis);
    $other = RoleScope::factory()->for($this->user)->create([
        'role' => Role::JefeDepartamento,
        'campus_id' => null,
        'department_id' => $this->sis->id,
        'valid_from' => today()->subDays(10)->toDateString(),
    ]);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    expect(RoleScope::whereKeyNot($other->id)->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($other->fresh()->valid_to)->toBeNull();
});

it('al terminar la jefatura de un jefe cierra su alcance sin campus, no uno con campus del mismo día', function () {
    $head = appointHead($this->user, $this->sis);
    $withCampus = RoleScope::factory()->for($this->user)->create([
        'role' => Role::JefeDepartamento,
        'campus_id' => $this->c2->id,
        'department_id' => $this->sis->id,
        'valid_from' => $head->valid_from->toDateString(),
    ]);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    expect(RoleScope::whereNull('campus_id')->sole()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($withCampus->fresh()->valid_to)->toBeNull()
        ->and(Activity::latest('id')->first()->properties['role_scope_ids'])->toBe([RoleScope::whereNull('campus_id')->sole()->id]);
});

it('termina una jefatura sin alcance y deja dicho en la bitácora que no encontró uno', function () {
    $head = DepartmentHead::factory()->create([
        'department_id' => $this->sis->id,
        'user_id' => $this->user->id,
    ]);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $entry = Activity::sole();

    expect($head->fresh()->valid_to->toDateString())->toBe(today()->subDay()->toDateString())
        ->and($entry->description)->toBe('Terminó la jefatura de Ana Pérez como jefe de departamento de Sistemas y Computación; no encontró su alcance')
        ->and($entry->properties['role_scope_ids'])->toBe([]);
});

it('no nombra jefe a un usuario inactivo', function () {
    $this->user->update(['is_active' => false]);

    try {
        appointHead($this->user, $this->sis);
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['user' => ['El usuario está inactivo.']]);
    }

    expect(DepartmentHead::count())->toBe(0)
        ->and(RoleScope::count())->toBe(0)
        ->and($this->user->fresh()->roles)->toBeEmpty()
        ->and(Activity::count())->toBe(0);
});
