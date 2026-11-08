<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Notifications\ChangeRequestApplied;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| MarkChangeRequestApplied (tarea 3.11). Decisión 1 (confirmado el 7 de noviembre de 2026): la carga vuelve sola a
| asignación cuando no queda una solicitud aprobada sin aplicar de ella, si Scheduling no lo veta.
*/

it('marca la solicitud como aplicada, con quién y cuándo, y avisa al solicitante', function () {
    Notification::fake();
    $s = changeScenario(LoadStatus::Adjusting);
    $request = requestFor($s['group'], 'approved', ['requested_by' => $s['teacherUser']->id]);

    $result = app(MarkChangeRequestApplied::class)->handle($request, $s['coordinador']);

    expect($result->request->status)->toBe(ChangeRequestStatus::Applied)
        ->and($result->request->applied_by)->toBe($s['coordinador']->id)
        ->and($result->request->applied_at)->not->toBeNull()
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'applied')->exists())->toBeTrue();
    Notification::assertSentTo($s['teacherUser'], ChangeRequestApplied::class);
});

it('al aplicar la última aprobada, la carga vuelve a asignación', function () {
    // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla el flujo.
    Event::forget(LoadTransitioning::class);
    $s = changeScenario(LoadStatus::Adjusting);
    $request = requestFor($s['group'], 'approved');

    $result = app(MarkChangeRequestApplied::class)->handle($request, $s['coordinador']);

    expect($result->returnedToStaffing)->toBeTrue()
        ->and($result->vetoReasons)->toBe([])
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Staffing);
});

it('con otra aprobada sin aplicar de la misma carga, se queda en ajustes', function () {
    $s = changeScenario(LoadStatus::Adjusting);
    requestFor($s['group'], 'approved', ['kind' => 'room']);

    $result = app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], 'approved'), $s['coordinador']);

    expect($result->returnedToStaffing)->toBeFalse()
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting);
});

it('una aprobada de otro departamento no retiene la carga', function () {
    // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla el flujo.
    Event::forget(LoadTransitioning::class);
    $s = changeScenario(LoadStatus::Adjusting);
    $other = changeScenario(LoadStatus::Adjusting);
    requestFor($other['group'], 'approved', ['term_id' => $s['group']->term_id, 'campus_id' => $s['group']->campus_id]);

    $result = app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], 'approved'), $s['coordinador']);

    expect($result->returnedToStaffing)->toBeTrue();
});

it('si Scheduling veta el regreso, la solicitud queda aplicada, la carga en ajustes y se devuelven los motivos', function () {
    // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla el flujo.
    Event::forget(LoadTransitioning::class);
    $s = changeScenario(LoadStatus::Adjusting);
    Event::listen(LoadTransitioning::class, function (LoadTransitioning $event): void {
        if ($event->to === LoadStatus::Staffing) {
            $event->block('Hay 2 choques duros en el horario.');
        }
    });

    $result = app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], 'approved'), $s['coordinador']);

    expect($result->request->fresh()->status)->toBe(ChangeRequestStatus::Applied)
        ->and($result->returnedToStaffing)->toBeFalse()
        ->and($result->vetoReasons)->toBe(['Hay 2 choques duros en el horario.'])
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting);
});

it('con la carga en otro estado solo marca aplicada', function () {
    $s = changeScenario(LoadStatus::Closed);

    $result = app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], 'approved'), $s['coordinador']);

    expect($result->request->status)->toBe(ChangeRequestStatus::Applied)
        ->and($result->returnedToStaffing)->toBeFalse()
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Closed);
});

it('solo se aplica una solicitud aprobada', function (string $state) {
    $s = changeScenario(LoadStatus::Adjusting);

    expect(fn () => app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], $state), $s['coordinador']))
        ->toThrow(ValidationException::class, 'no está aprobada');
})->with(['pending', 'rejected', 'applied']);
