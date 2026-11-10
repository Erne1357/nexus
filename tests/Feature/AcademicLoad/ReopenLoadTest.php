<?php

use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| ReopenLoad reabre una carga cerrada, aprobada o publicada: vuelve a ajustes con un motivo obligatorio, que
| queda como comentario de su historial (P3, confirmado el 10 de noviembre de 2026). La hacen Subdirección o el
| admin. Volver a publicar crea otra versión del snapshot (tarea 4.3).
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
});

it('reabre a ajustes con el motivo en su historial', function (LoadStatus $status) {
    Event::fake([LoadTransitioned::class]);
    $load = AcademicLoad::factory()->inStatus($status)->create();

    app(ReopenLoad::class)->handle($load, $this->user, '  Faltó el grupo vespertino de Cálculo  ');

    expect($load->fresh()->status)->toBe(LoadStatus::Adjusting);

    $row = LoadTransition::query()->sole();
    expect($row->from_status)->toBe($status)
        ->and($row->to_status)->toBe(LoadStatus::Adjusting)
        ->and($row->comment)->toBe('Faltó el grupo vespertino de Cálculo');

    Event::assertDispatched(LoadTransitioned::class, fn (LoadTransitioned $event): bool => $event->to === LoadStatus::Adjusting);
})->with([LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published]);

it('exige el motivo', function (?string $reason) {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create();

    expect(fn () => app(ReopenLoad::class)->handle($load, $this->user, $reason))->toThrow(
        fn (ValidationException $e) => expect($e->errors())->toBe(['reason' => ['Escribe el motivo para reabrir la carga.']]),
    );

    expect($load->fresh()->status)->toBe(LoadStatus::Published)
        ->and(LoadTransition::query()->count())->toBe(0);
})->with(['vacío' => [''], 'espacios' => ['   '], 'nulo' => [null]]);

it('no reabre una carga que todavía se está armando', function (LoadStatus $status) {
    $load = AcademicLoad::factory()->inStatus($status)->create();

    expect(fn () => app(ReopenLoad::class)->handle($load, $this->user, 'Corregir'))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'][0])->toStartWith("La carga está en {$status->label()}:"),
    );
})->with([LoadStatus::Draft, LoadStatus::Proposed, LoadStatus::Staffing, LoadStatus::Adjusting]);

it('la policy deja reabrir al admin y a Subdirección, y niega a los demás', function (?Role $role, bool $expected) {
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

    expect($user->can('reopen', $load))->toBe($expected);
})->with([
    'admin' => [null, true],
    'subdirección' => [Role::Subdireccion, true],
    'jefe del departamento' => [Role::JefeDepartamento, false],
    'jefe de proyecto docente' => [Role::JefeProyectoDocente, false],
    'coordinador' => [Role::Coordinador, false],
]);
