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
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\User;
use App\Modules\Core\Notifications\NotificationMessage;
use Illuminate\Support\Facades\Notification;

/*
| Tarea 4.2: la campana avisa al jefe cuando Subdirección reabre o aprueba su carga, y a Subdirección cuando una
| carga cerrada espera su visto bueno. Reciben los titulares del permiso en el campus y el departamento de la carga.
*/

function approvalNoticeScenario(bool $requiresApproval = true, LoadStatus $status = LoadStatus::Closed): array
{
    $campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'requires_subdireccion_approval' => $requiresApproval]]);
    $department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $other = Department::factory()->create();

    return [
        'load' => AcademicLoad::factory()->inStatus($status)->create(['campus_id' => $campus->id, 'department_id' => $department->id]),
        'jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $department->id])->create(),
        'otroJefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => $other->id])->create(),
        'subdireccion' => User::factory()->withRole(Role::Subdireccion)->create(),
        'proyecto' => User::factory()->withRole(Role::JefeProyectoDocente, ['campus_id' => $campus->id, 'department_id' => $department->id])->create(),
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
            && $message->campusId === $s['load']->campus_id
            && $message->termId === $s['load']->term_id;
    });
    Notification::assertNotSentTo([$s['otroJefe'], $s['subdireccion'], $s['admin'], $s['proyecto']], LoadReopened::class);
})->with([LoadStatus::Closed, LoadStatus::Approved, LoadStatus::Published]);

it('al cerrar en un campus que exige visto bueno, avisa a Subdirección', function () {
    Notification::fake();
    $s = approvalNoticeScenario(true, LoadStatus::Staffing);
    $this->actingAs($s['jefe']);

    app(CloseLoad::class)->handle($s['load'], $s['jefe']);

    Notification::assertSentTo($s['subdireccion'], LoadAwaitingApproval::class, fn (LoadAwaitingApproval $n) => $n->message($s['subdireccion'])->title === 'La carga de Sistemas y Computación espera tu visto bueno');
    Notification::assertNotSentTo([$s['jefe'], $s['otroJefe'], $s['admin'], $s['proyecto']], LoadAwaitingApproval::class);
});

it('al cerrar en un campus que no lo exige, no avisa', function () {
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

it('guarda el puesto y, mientras no exista la pantalla de cierre, el aviso dice que no está disponible', function () {
    $s = approvalNoticeScenario();
    $this->actingAs($s['subdireccion']);

    app(ReopenLoad::class)->handle($s['load'], $s['subdireccion'], 'Corregir');
    $stored = $s['jefe']->notifications()->sole();
    $message = NotificationMessage::fromArray($stored->data);

    expect($stored->data['role'])->toBe('jefe_departamento')
        ->and(LoadReopened::unavailableReason(app(Actor::class)->forUser($s['jefe']), $message))->toBe('Esa pantalla no está disponible.');
});
