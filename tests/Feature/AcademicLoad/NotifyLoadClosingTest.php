<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Actions\Transitions\ApproveLoad;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Actions\Transitions\ReopenLoad;
use App\Modules\AcademicLoad\Actions\Transitions\StartStaffing;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Notifications\LoadApproved;
use App\Modules\AcademicLoad\Notifications\LoadAwaitingApproval;
use App\Modules\AcademicLoad\Notifications\LoadReopened;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Notification;

/*
| Tarea 4.2: la campana avisa al jefe cuando Subdirección reabre o aprueba su carga, y a Subdirección cuando una
| carga cerrada espera su visto bueno. Reciben los titulares del permiso en la modalidad y el departamento de la carga
| (M1); el aviso abre la pantalla en la modalidad, sin campus (MS14).
*/

function approvalNoticeScenario(bool $requiresApproval = true, LoadStatus $status = LoadStatus::Closed): array
{
    $campus = Campus::factory()->create();
    $campus->catalogModality->update(['settings' => [...ModalityProfile::DEFAULTS, 'requires_subdireccion_approval' => $requiresApproval]]);
    $department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $other = Department::factory()->create();

    return [
        'load' => AcademicLoad::factory()->inStatus($status)->create(['modality_id' => $campus->modality_id, 'department_id' => $department->id]),
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(),
        'otroJefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $other->id])->create(),
        'subdireccion' => User::factory()->withRole(Role::Subdireccion)->create(),
        'proyecto' => User::factory()->withRole(Role::JefeProyectoDocente, ['modality_id' => $campus->modality_id, 'department_id' => $department->id])->create(),
        'admin' => User::factory()->admin()->create(),
        'department' => $department,
    ];
}

it('al reabrir, avisa al jefe del departamento con el motivo', function (LoadStatus $status) {
    Notification::fake();
    $s = approvalNoticeScenario(true, $status);
    $this->actingAs($s['subdireccion']);

    app(ReopenLoad::class)->handle($s['load'], $s['subdireccion'], 'Falta el grupo vespertino de Cálculo');

    Notification::assertSentTo($s['jefe'], LoadReopened::class, function (LoadReopened $notification) use ($s) {
        $message = $notification->message($s['jefe']);

        return $message->title === 'Subdirección reabrió la carga de Sistemas y Computación'
            && $message->body === 'Falta el grupo vespertino de Cálculo'
            && $message->route === 'staffing.closing'
            && $message->parameters === ['departamento' => $s['department']->id]
            && $message->modalityId === $s['load']->modality_id && $message->campusId === null
            && $message->termId === $s['load']->term_id;
    });
    Notification::assertNotSentTo([$s['otroJefe'], $s['subdireccion'], $s['admin'], $s['proyecto']], LoadReopened::class);
})->with([LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published]);

it('al cerrar en una modalidad que exige visto bueno, avisa a Subdirección', function () {
    Notification::fake();
    $s = approvalNoticeScenario(true, LoadStatus::Staffing);
    $this->actingAs($s['jefe']);

    app(CloseLoad::class)->handle($s['load'], $s['jefe']);

    Notification::assertSentTo($s['subdireccion'], LoadAwaitingApproval::class, fn (LoadAwaitingApproval $n) => $n->message($s['subdireccion'])->title === 'La carga de Sistemas y Computación espera tu visto bueno');
    Notification::assertNotSentTo([$s['jefe'], $s['otroJefe'], $s['admin'], $s['proyecto']], LoadAwaitingApproval::class);
});

it('al cerrar en una modalidad que no lo exige, no avisa', function () {
    Notification::fake();
    $s = approvalNoticeScenario(false, LoadStatus::Staffing);
    $this->actingAs($s['jefe']);

    app(CloseLoad::class)->handle($s['load'], $s['jefe']);

    Notification::assertNothingSent();
});

it('al aprobar, avisa al jefe del departamento', function () {
    Notification::fake();
    $s = approvalNoticeScenario();
    $this->actingAs($s['subdireccion']);

    app(ApproveLoad::class)->handle($s['load'], $s['subdireccion']);

    Notification::assertSentTo($s['jefe'], LoadApproved::class, fn (LoadApproved $n) => $n->message($s['jefe'])->title === 'Subdirección dio el visto bueno a la carga de Sistemas y Computación');
    Notification::assertNotSentTo([$s['otroJefe'], $s['subdireccion'], $s['admin'], $s['proyecto']], LoadApproved::class);
});

it('una transición que no aplica no avisa', function () {
    Notification::fake();
    $s = approvalNoticeScenario(true, LoadStatus::Proposed);
    $this->actingAs($s['jefe']);

    app(StartStaffing::class)->handle($s['load'], $s['jefe']);

    Notification::assertNothingSent();
});

it('guarda el puesto y lleva a «Cierre de la carga» con el departamento', function () {
    $s = approvalNoticeScenario();
    $this->actingAs($s['subdireccion']);

    app(ReopenLoad::class)->handle($s['load'], $s['subdireccion'], 'Corregir');
    $stored = $s['jefe']->notifications()->sole();
    $message = NotificationMessage::fromArray($stored->data);

    expect($stored->data['role'])->toBe('jefe_departamento')
        ->and(LoadReopened::unavailableReason(app(Actor::class)->forUser($s['jefe']), $message))->toBeNull()
        ->and(route($message->route, $message->parameters))->toBe(route('staffing.closing', ['departamento' => $s['department']->id]));
});

it('Subdirección cierra también, pero los avisos al jefe no le llegan', function () {
    Notification::fake();
    $s = approvalNoticeScenario();
    $this->actingAs($s['subdireccion']);

    app(ApproveLoad::class)->handle($s['load'], $s['subdireccion']);
    app(ReopenLoad::class)->handle($s['load']->fresh(), $s['subdireccion'], 'Falta un grupo');

    Notification::assertSentTo($s['jefe'], LoadApproved::class);
    Notification::assertSentTo($s['jefe'], LoadReopened::class);
    Notification::assertNotSentTo($s['subdireccion'], LoadApproved::class);
    Notification::assertNotSentTo($s['subdireccion'], LoadReopened::class);
});
