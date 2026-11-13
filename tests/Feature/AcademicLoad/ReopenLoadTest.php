<?php

use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\StartAdjusting;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioned;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadTransition;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use Illuminate\Support\Facades\DB;
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

it('M11: reabrir toma primero la modalidad de la carga (FOR SHARE) y después la carga, el orden en que la desactivación las bloquea', function () {
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Published)->create();
    DB::enableQueryLog();

    app(ReopenLoad::class)->handle($load, $this->user, 'Faltó un grupo');

    $locks = collect(DB::getQueryLog())->pluck('query')
        ->filter(fn (string $query): bool => str_contains($query, 'for share') || str_contains($query, 'for update'))
        ->values();

    expect($locks->first())->toContain('from "modalities"')->toContain('for share')
        ->and($locks->search(fn (string $query): bool => str_contains($query, 'from "academic_loads"') && str_contains($query, 'for update')))->toBe(1);
});

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
    $load = AcademicLoad::factory()->inStatus(LoadStatus::Closed)->create(['modality_id' => modalityOf('PRES')->id, 'department_id' => departmentKey(10)]);
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

it('desde cerrada, aprobada o publicada solo se pasa a ajustes reabriendo, no con una solicitud de cambio', function (LoadStatus $status) {
    $load = AcademicLoad::factory()->inStatus($status)->create();

    expect(fn () => app(StartAdjusting::class)->handle($load, $this->user, 'Solicitud de cambio aprobada'))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe(["La carga está en {$status->label()}: una solicitud de cambio solo la pasa a ajustes desde asignación."]),
    );

    expect($load->fresh()->status)->toBe($status);
})->with([LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published]);
