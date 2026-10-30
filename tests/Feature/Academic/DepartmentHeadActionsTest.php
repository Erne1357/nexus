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
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| Jefaturas (confirmado el 30 de octubre de 2026): nombrar a un jefe o a un jefe de proyecto docente de un
| departamento en un campus escribe, en la misma transacción, la fila de `department_heads`, el rol de spatie y
| un alcance con el mismo campus, departamento y fechas. Terminarla cierra las dos con la regla de
| CloseRoleScopes: la que ya empezó termina en el último día; la que empieza después, se borra.
*/

beforeEach(function () {
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->sis = Department::factory()->create(['code' => 'SIS', 'name' => 'Sistemas y Computación']);
    $this->ind = Department::factory()->create(['code' => 'IND', 'name' => 'Industrial']);
    $this->user = User::factory()->create(['username' => '1234567', 'name' => 'Ana Pérez']);
});

function appointHead(User $user, Department $department, Campus $campus, DepartmentHeadKind $kind = DepartmentHeadKind::Jefe, ?CarbonImmutable $from = null, ?CarbonImmutable $to = null): DepartmentHead
{
    return app(AppointDepartmentHead::class)->handle($department, $campus, $user, $kind, $from ?? today()->subMonth()->toImmutable(), $to);
}

it('nombrar a un jefe le da la fila, el rol y un alcance con las mismas fechas', function () {
    Event::fake([DepartmentHeadAppointed::class]);
    $from = today()->subDays(3)->toImmutable();
    $to = today()->addMonths(6)->toImmutable();

    $head = appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::Jefe, $from, $to);

    $scope = RoleScope::sole();
    $context = app(ScopedContext::class)->forUser($this->user->fresh());

    expect($head->fresh())
        ->department_id->toBe($this->sis->id)
        ->campus_id->toBe($this->c2->id)
        ->user_id->toBe($this->user->id)
        ->kind->toBe(DepartmentHeadKind::Jefe)
        ->and($head->fresh()->valid_from->toDateString())->toBe($from->toDateString())
        ->and($head->fresh()->valid_to->toDateString())->toBe($to->toDateString())
        ->and($this->user->fresh()->hasRole(Role::JefeDepartamento->value))->toBeTrue()
        ->and($scope)
        ->user_id->toBe($this->user->id)
        ->role->toBe(Role::JefeDepartamento)
        ->campus_id->toBe($this->c2->id)
        ->department_id->toBe($this->sis->id)
        ->career_id->toBeNull()
        ->and($scope->valid_from->toDateString())->toBe($from->toDateString())
        ->and($scope->valid_to->toDateString())->toBe($to->toDateString())
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->sis->id))->toBeTrue()
        ->and($context->covers(Role::JefeDepartamento, $this->c1, $this->sis->id))->toBeFalse()
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->ind->id))->toBeFalse();

    Event::assertDispatched(DepartmentHeadAppointed::class, fn (DepartmentHeadAppointed $event): bool => $event->head->is($head));
});

it('deja en la bitácora del usuario a quién nombró, de qué y desde cuándo', function () {
    $head = appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::JefeProyectoDocente);

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

it('un segundo jefe vigente del mismo departamento y campus es error de validación en user', function () {
    appointHead($this->user, $this->sis, $this->c2);
    $other = User::factory()->create();

    try {
        appointHead($other, $this->sis, $this->c2, from: today()->toImmutable());
        $this->fail('Se esperaba ValidationException.');
    } catch (ValidationException $exception) {
        expect($exception->errors())->toBe(['user' => ['Ya hay un jefe de Sistemas y Computación vigente en Campus II en esas fechas.']]);
    }

    expect(DepartmentHead::count())->toBe(1)
        ->and($other->fresh()->roles)->toBeEmpty()
        ->and($other->roleScopes()->exists())->toBeFalse()
        ->and(Activity::count())->toBe(1);
});

it('el mismo jefe en otro campus o en otro departamento sí se puede', function () {
    appointHead($this->user, $this->sis, $this->c2);

    appointHead(User::factory()->create(), $this->sis, $this->c1);
    appointHead(User::factory()->create(), $this->ind, $this->c2);

    expect(DepartmentHead::count())->toBe(3);
});

it('dos jefes de proyecto docente vigentes a la vez sí', function () {
    $other = User::factory()->create();

    appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::JefeProyectoDocente);
    appointHead($other, $this->sis, $this->c2, DepartmentHeadKind::JefeProyectoDocente);

    expect(DepartmentHead::count())->toBe(2)
        ->and(app(ScopedContext::class)->forUser($this->user)->covers(Role::JefeProyectoDocente, $this->c2, $this->sis->id))->toBeTrue()
        ->and(app(ScopedContext::class)->forUser($other)->covers(Role::JefeProyectoDocente, $this->c2, $this->sis->id))->toBeTrue();
});

it('la misma persona no es dos veces jefe de proyecto docente en fechas que se traslapan', function () {
    appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::JefeProyectoDocente);

    expect(fn () => appointHead($this->user, $this->sis, $this->c2, DepartmentHeadKind::JefeProyectoDocente, today()->toImmutable()))
        ->toThrow(ValidationException::class, 'Ana Pérez ya es jefe de proyecto docente de Sistemas y Computación en Campus II en esas fechas.');

    expect(DepartmentHead::count())->toBe(1)
        ->and(RoleScope::count())->toBe(1);
});

it('rechaza una jefatura que termina antes de empezar', function () {
    expect(fn () => appointHead($this->user, $this->sis, $this->c2, from: today()->toImmutable(), to: today()->subDay()->toImmutable()))
        ->toThrow(ValidationException::class, 'La fecha de fin no puede ser anterior a la de inicio.');

    expect(DepartmentHead::count())->toBe(0)
        ->and(RoleScope::count())->toBe(0);
});

it('recarga el contexto del usuario autenticado al nombrarlo', function () {
    $this->actingAs($this->user);

    expect(app(ScopedContext::class)->has(Role::JefeDepartamento))->toBeFalse();

    appointHead($this->user, $this->sis, $this->c2);

    expect(app(ScopedContext::class)->has(Role::JefeDepartamento))->toBeTrue();
});

it('terminar ayer una jefatura deja de cubrir hoy', function () {
    Event::fake([DepartmentHeadEnded::class]);
    $head = appointHead($this->user, $this->sis, $this->c2);
    $context = app(ScopedContext::class)->forUser($this->user);

    expect($context->covers(Role::JefeDepartamento, $this->c2, $this->sis->id))->toBeTrue();

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $yesterday = today()->subDay()->toDateString();

    expect($head->fresh()->valid_to->toDateString())->toBe($yesterday)
        ->and(RoleScope::sole()->valid_to->toDateString())->toBe($yesterday)
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->sis->id))->toBeFalse();

    $entry = Activity::latest('id')->first();

    expect($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->event)->toBe('updated')
        ->and($entry->description)->toBe('Terminó la jefatura de Ana Pérez como jefe de departamento de Sistemas y Computación en Campus II')
        ->and($entry->attribute_changes->all())->toBe([
            'attributes' => ['valid_to' => $yesterday],
            'old' => ['valid_to' => null],
        ]);

    Event::assertDispatched(DepartmentHeadEnded::class, fn (DepartmentHeadEnded $event): bool => $event->head->is($head));
});

it('terminar una jefatura que empieza mañana la borra con su alcance', function () {
    $head = appointHead($this->user, $this->sis, $this->c2, from: today()->addDay()->toImmutable());

    app(EndDepartmentHead::class)->handle($head, today()->toImmutable());

    $entry = Activity::latest('id')->first();

    expect(DepartmentHead::find($head->id))->toBeNull()
        ->and(DepartmentHead::withTrashed()->find($head->id)->trashed())->toBeTrue()
        ->and(RoleScope::count())->toBe(0)
        ->and($entry->subject->is($this->user))->toBeTrue()
        ->and($entry->event)->toBe('deleted')
        ->and($entry->description)->toBe('Quitó la jefatura de Ana Pérez como jefe de departamento de Sistemas y Computación en Campus II, que todavía no empezaba')
        ->and($entry->attribute_changes['old'])->toMatchArray([
            'department_head_id' => $head->id,
            'kind' => 'jefe',
            'valid_from' => today()->addDay()->toDateString(),
            'valid_to' => null,
        ]);
});

it('una jefatura terminada deja lugar a otro jefe desde el día siguiente', function () {
    $head = appointHead($this->user, $this->sis, $this->c2);
    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $next = appointHead(User::factory()->create(), $this->sis, $this->c2, from: today()->toImmutable());

    expect($next->exists)->toBeTrue();
});

it('no alarga una jefatura que ya termina antes del último día dado', function () {
    $end = today()->addDays(10)->toImmutable();
    $head = appointHead($this->user, $this->sis, $this->c2, to: $end);
    $entries = Activity::count();

    app(EndDepartmentHead::class)->handle($head, today()->addDays(20)->toImmutable());

    expect($head->fresh()->valid_to->toDateString())->toBe($end->toDateString())
        ->and(RoleScope::sole()->valid_to->toDateString())->toBe($end->toDateString())
        ->and(Activity::count())->toBe($entries);
});

it('termina el alcance de su jefatura y no los de otras', function () {
    $head = appointHead($this->user, $this->sis, $this->c2);
    appointHead($this->user, $this->ind, $this->c2);
    appointHead($this->user, $this->sis, $this->c1);

    app(EndDepartmentHead::class)->handle($head, today()->subDay()->toImmutable());

    $context = app(ScopedContext::class)->forUser($this->user);

    expect($context->covers(Role::JefeDepartamento, $this->c2, $this->sis->id))->toBeFalse()
        ->and($context->covers(Role::JefeDepartamento, $this->c2, $this->ind->id))->toBeTrue()
        ->and($context->covers(Role::JefeDepartamento, $this->c1, $this->sis->id))->toBeTrue();
});
