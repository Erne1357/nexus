<?php

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Staffing\Actions\ChangeRequests\ApproveChangeRequest;
use App\Modules\Staffing\Actions\ChangeRequests\RejectChangeRequest;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Notifications\ChangeRequestApproved;
use App\Modules\Staffing\Notifications\ChangeRequestPendingApplication;
use App\Modules\Staffing\Notifications\ChangeRequestRejected;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| ApproveChangeRequest y RejectChangeRequest (tarea 3.11). Decisión 1 (confirmado el 7 de noviembre de 2026): aprobar
| pasa la carga de asignación a ajustes.
*/

it('aprobar deja la solicitud aprobada, con quién y cuándo, y pasa la carga de asignación a ajustes', function () {
    $s = changeScenario();
    $request = requestFor($s['group']);

    $approved = app(ApproveChangeRequest::class)->handle($request, $s['jefe'], 'Adelante');

    expect($approved->status)->toBe(ChangeRequestStatus::Approved)
        ->and($approved->decided_by)->toBe($s['jefe']->id)
        ->and($approved->decided_at)->not->toBeNull()
        ->and($approved->decision_comment)->toBe('Adelante')
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'approved')->exists())->toBeTrue();
});

it('aprobar con la carga ya en ajustes la deja ahí', function () {
    $s = changeScenario(LoadStatus::Adjusting);

    app(ApproveChangeRequest::class)->handle(requestFor($s['group']), $s['jefe']);

    expect($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting);
});

it('aprobar avisa al solicitante y, en un cambio de horario, al coordinador de la carrera dueña', function () {
    Notification::fake();
    $s = changeScenario();
    $otraCarrera = User::factory()->withRole(Role::Coordinador, ['campus_id' => $s['group']->campus_id, 'career_id' => Career::factory()->create()->id])->create();

    app(ApproveChangeRequest::class)->handle(requestFor($s['group'], 'pending', ['teacher_id' => $s['teacher']->id, 'requested_by' => $s['teacherUser']->id]), $s['jefe']);

    Notification::assertSentTo($s['teacherUser'], ChangeRequestApproved::class);
    Notification::assertSentTo($s['coordinador'], ChangeRequestPendingApplication::class);
    Notification::assertNotSentTo($s['jefe'], ChangeRequestPendingApplication::class);
    Notification::assertNotSentTo($otraCarrera, ChangeRequestPendingApplication::class);
});

it('aprobar un cambio de salón avisa al jefe del departamento, no al coordinador', function () {
    Notification::fake();
    $s = changeScenario();

    app(ApproveChangeRequest::class)->handle(requestFor($s['group'], 'pending', ['kind' => 'room']), $s['jefe']);

    Notification::assertSentTo($s['jefe'], ChangeRequestPendingApplication::class);
    Notification::assertNotSentTo($s['coordinador'], ChangeRequestPendingApplication::class);
});

it('solo se aprueba o rechaza una solicitud pendiente', function (string $state) {
    $s = changeScenario();
    $request = requestFor($s['group'], $state);

    expect(fn () => app(ApproveChangeRequest::class)->handle($request, $s['jefe']))->toThrow(ValidationException::class, 'ya no está pendiente')
        ->and(fn () => app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No'))->toThrow(ValidationException::class, 'ya no está pendiente');
})->with(['approved', 'rejected', 'applied']);

it('rechazar exige comentario, deja la carga como estaba y avisa al solicitante', function () {
    Notification::fake();
    $s = changeScenario();
    $request = requestFor($s['group'], 'pending', ['requested_by' => $s['teacherUser']->id]);

    expect(fn () => app(RejectChangeRequest::class)->handle($request, $s['jefe'], '  '))->toThrow(ValidationException::class)
        ->and($request->fresh()->status)->toBe(ChangeRequestStatus::Pending);

    $rejected = app(RejectChangeRequest::class)->handle($request, $s['jefe'], 'No hay otro horario');

    expect($rejected->status)->toBe(ChangeRequestStatus::Rejected)
        ->and($rejected->decision_comment)->toBe('No hay otro horario')
        ->and($rejected->decided_by)->toBe($s['jefe']->id)
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Staffing);
    Notification::assertSentTo($s['teacherUser'], ChangeRequestRejected::class);
});

it('con la carga en otro estado solo aprueba, sin tocarla', function () {
    $s = changeScenario(LoadStatus::Closed);
    $request = requestFor($s['group']);

    app(ApproveChangeRequest::class)->handle($request, $s['jefe']);

    expect($request->fresh()->status)->toBe(ChangeRequestStatus::Approved)
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Closed);
});
