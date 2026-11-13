<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadTransitioning;
use App\Modules\Core\Models\Campus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\ChangeRequests\MarkChangeRequestApplied;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Notifications\ChangeRequestApplied;
use App\Modules\Staffing\Queries\AssignmentBoardQuery;
use App\Modules\Staffing\Queries\ChangeRequestsQuery;
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

it('M1: una aprobada de un grupo de otro campus de la misma carga presencial la retiene en ajustes', function () {
    // Sin el veto de Scheduling, si se intentara el regreso a asignación, pasaría: así se ve que no se intenta.
    Event::forget(LoadTransitioning::class);
    $s = changeScenario(LoadStatus::Adjusting);
    $otherCampus = Group::factory()->create([
        'term_id' => $s['group']->term_id,
        'campus_id' => Campus::factory()->create(['code' => 'C9'])->id,
        'subject_id' => $s['group']->subject_id,
    ]);
    requestFor($otherCampus, 'approved');

    $result = app(MarkChangeRequestApplied::class)->handle(requestFor($s['group'], 'approved'), $s['coordinador']);

    expect($otherCampus->modality_id)->toBe($s['load']->modality_id)
        ->and($result->returnedToStaffing)->toBeFalse()
        ->and($s['load']->fresh()->status)->toBe(LoadStatus::Adjusting)
        ->and($s['load']->transitions()->count())->toBe(0);
});

it('una aprobada de otro departamento no retiene la carga', function () {
    // El veto real de Scheduling (BlockStaffingWithHardConflicts) tiene sus pruebas; aquí se aísla el flujo.
    Event::forget(LoadTransitioning::class);
    $s = changeScenario(LoadStatus::Adjusting);
    $other = changeScenario(LoadStatus::Adjusting);
    requestFor($other['group'], 'approved', ['term_id' => $s['group']->term_id]);

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

it('M1: la carga de las solicitudes es la del departamento en la modalidad; en otra modalidad, ninguna', function () {
    $s = changeScenario(LoadStatus::Adjusting);
    $ead = modalityOf('EAD');

    expect(app(ChangeRequestsQuery::class)->loadOf($s['group']->term_id, $ead->id, $s['group']->department_id))->toBe(['load' => null, 'approved' => 0])
        ->and(app(AssignmentBoardQuery::class)->load($s['group']->term_id, $ead->id, $s['group']->department_id))->toBeNull()
        ->and(app(ChangeRequestsQuery::class)->loadOf($s['group']->term_id, $s['group']->modality_id, $s['group']->department_id)['load']?->is($s['load']))->toBeTrue();
});
