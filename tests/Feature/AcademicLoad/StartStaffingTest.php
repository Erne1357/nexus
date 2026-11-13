<?php

use App\Modules\AcademicLoad\Actions\Transitions\StartStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| StartStaffing pasa la carga de propuesta a asignación docente. No tiene guarda de módulos a la izquierda: los
| choques duros y los grupos sin horario los veta Scheduling con LoadTransitioning. Desde ahí se asignan
| docentes y la oferta del departamento es visible para sus docentes (HC-17).
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('pasa la carga de propuesta a asignación docente con su historial, bitácora y evento', function () {
    Event::fake([LoadTransitioned::class]);
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create();

    app(StartStaffing::class)->handle($load, $this->user, 'Listos para asignar');

    expect($load->fresh()->status)->toBe(LoadStatus::Staffing);

    $row = LoadTransition::query()->firstOrFail();
    expect($row->from_status)->toBe(LoadStatus::Proposed)
        ->and($row->to_status)->toBe(LoadStatus::Staffing)
        ->and($row->user_id)->toBe($this->user->id)
        ->and($row->comment)->toBe('Listos para asignar');

    $activity = Activity::query()->where('event', 'transitioned')->firstOrFail();
    expect($activity->subject_id)->toBe($load->id)
        ->and($activity->attribute_changes['attributes'])->toBe(['status' => 'staffing']);

    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->load->is($load)
        && $event->from === LoadStatus::Proposed && $event->to === LoadStatus::Staffing);
});

it('rechaza desde borrador o desde asignación con el estado en el mensaje', function (LoadStatus $status, string $message) {
    $load = AcademicLoad::factory()->inStatus($status)->create();

    expect(fn () => app(StartStaffing::class)->handle($load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'][0])->toStartWith($message),
    );

    expect($load->fresh()->status)->toBe($status)
        ->and(LoadTransition::query()->count())->toBe(0);
})->with([
    'borrador' => [LoadStatus::Draft, 'La carga está en Borrador'],
    'asignación' => [LoadStatus::Staffing, 'La carga está en Asignación:'],
]);

it('un listener de LoadTransitioning que veta bloquea la transición y no escribe nada', function () {
    Event::listen(LoadTransitioning::class, fn (LoadTransitioning $event) => $event->block('Hay grupos sin horario.'));
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create();

    expect(fn () => app(StartStaffing::class)->handle($load, $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(['Hay grupos sin horario.']),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Proposed)
        ->and(LoadTransition::query()->count())->toBe(0)
        ->and(Activity::query()->where('event', 'transitioned')->count())->toBe(0);
});

it('la policy deja pasar al admin y al jefe del departamento, y niega a los demás', function (?Role $role, bool $sameDepartment, bool $expected) {
    $campus = Campus::factory()->create();
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Proposed)->create(['modality_id' => $campus->modality_id, 'department_id' => departmentKey(10)]);
    $user = $role === null
        ? User::factory()->admin()->create()
        : User::factory()->withRole($role, [
            'campus_id' => $campus->id,
            ...($role === Role::Coordinador ? ['career_id' => careerKey(20)] : ['department_id' => departmentKey($sameDepartment ? 10 : 11)]),
        ])->create();

    expect($user->can('startStaffing', $load))->toBe($expected);
})->with([
    'admin' => [null, true, true],
    'jefe del departamento' => [Role::JefeDepartamento, true, true],
    'jefe de otro departamento' => [Role::JefeDepartamento, false, false],
    'coordinador' => [Role::Coordinador, true, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, true, false],
]);
