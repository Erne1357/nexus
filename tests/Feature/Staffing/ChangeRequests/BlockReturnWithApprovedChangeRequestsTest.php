<?php

use App\Modules\AcademicLoad\Actions\Transitions\ReturnToStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Models\ChangeRequest;
use Illuminate\Validation\ValidationException;

/*
| «Volver a asignación» (`adjusting` → `staffing`, tarea 4.2) la veta Staffing mientras el departamento tenga
| solicitudes de cambio aprobadas sin aplicar en el campus y el periodo de la carga.
*/

beforeEach(function () {
    $this->user = User::factory()->admin()->create();
    $this->actingAs($this->user);
    $this->scenario = returnVetoScenario();
});

/**
 * Una carga en ajustes sin grupos, para que solo veten las solicitudes y no el horario.
 *
 * @return array{load: AcademicLoad}
 */
function returnVetoScenario(): array
{
    return ['load' => AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create()];
}

function returnVetoRequest(array $scenario, string $state = 'approved'): ChangeRequest
{
    $load = $scenario['load'];

    return ChangeRequest::factory()->{$state}()->create([
        'term_id' => $load->term_id,
        'modality_id' => $load->modality_id,
        'department_id' => $load->department_id,
    ]);
}

it('regresa a asignación sin solicitudes aprobadas sin aplicar', function () {
    returnVetoRequest($this->scenario, 'applied');
    returnVetoRequest($this->scenario, 'rejected');

    app(ReturnToStaffing::class)->handle($this->scenario['load'], $this->user, 'Todo aplicado');

    expect($this->scenario['load']->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('veta con la cifra de solicitudes aprobadas sin aplicar', function (int $count, string $message) {
    foreach (range(1, $count) as $_) {
        returnVetoRequest($this->scenario);
    }

    expect(fn () => app(ReturnToStaffing::class)->handle($this->scenario['load'], $this->user))->toThrow(
        fn (ValidationException $e) => expect($e->errors()['status'])->toBe([$message]),
    );

    expect($this->scenario['load']->fresh()->status)->toBe(LoadStatus::Adjusting);
})->with([
    'una' => [1, '1 solicitud de cambio aprobada sin aplicar: aplícalas antes de volver a asignación.'],
    'varias' => [3, '3 solicitudes de cambio aprobadas sin aplicar: aplícalas antes de volver a asignación.'],
]);

it('no cuenta las solicitudes de otro departamento ni de otro campus', function () {
    $other = returnVetoScenario();
    returnVetoRequest($other);

    app(ReturnToStaffing::class)->handle($this->scenario['load'], $this->user);

    expect($this->scenario['load']->fresh()->status)->toBe(LoadStatus::Staffing);
});
