<?php

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use App\Modules\Staffing\Enums\ChangeRequestStatus;
use App\Modules\Staffing\Notifications\ChangeRequested;
use Illuminate\Support\Facades\Notification;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| RequestChange (tarea 3.11): el docente del grupo o el coordinador de su carrera piden un cambio de horario o de
| salón; queda pendiente con una foto de las sesiones y avisa a los jefes del departamento.
*/

function askChange(array $s, ?User $actor = null, ChangeRequestKind $kind = ChangeRequestKind::Schedule)
{
    return app(RequestChange::class)->handle($s['group'], $actor ?? $s['teacherUser'], $kind, 'Los martes de 7 a 9', 'Doy clase en otro campus');
}

it('el docente del grupo lo pide: queda pendiente con su docente y la foto de las sesiones', function () {
    $s = changeScenario();
    $room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $s['group']->campus_id])->id, 'code' => 'A-101']);
    GroupSession::factory()->on(2, '07:00', '09:00')->inRoom($room)->create(['group_id' => $s['group']->id]);

    $request = askChange($s);

    expect($request->status)->toBe(ChangeRequestStatus::Pending)
        ->and($request->kind)->toBe(ChangeRequestKind::Schedule)
        ->and($request->teacher_id)->toBe($s['teacher']->id)
        ->and($request->requested_by)->toBe($s['teacherUser']->id)
        ->and($request->department_id)->toBe($s['group']->department_id)
        ->and($request->modality_id)->toBe($s['group']->modality_id)
        ->and($request->reason)->toBe('Doy clase en otro campus')
        ->and($request->proposed['description'])->toBe('Los martes de 7 a 9')
        ->and($request->current)->toHaveCount(1)
        ->and($request->current[0])->toMatchArray(['day' => 2, 'room' => 'A-101']);
});

it('la solicitud copia la modalidad de su grupo (ampliación de la ronda 2)', function () {
    $s = changeScenario();
    $ead = Modality::query()->where('code', 'EAD')->sole();
    $ead->update(['is_active' => true]);
    $s['group']->update(['modality_id' => $ead->id]);
    $s['load']->update(['modality_id' => $ead->id]);

    expect(askChange($s)->fresh()->modality->code)->toBe('EAD');
});

it('el coordinador lo pide sin docente y sin el límite de una abierta', function () {
    $s = changeScenario();

    $first = askChange($s, $s['coordinador'], ChangeRequestKind::Room);
    $second = askChange($s, $s['coordinador'], ChangeRequestKind::Room);

    expect($first->teacher_id)->toBeNull()
        ->and($first->requested_by)->toBe($s['coordinador']->id)
        ->and($second->exists)->toBeTrue();
});

it('lo registra en la bitácora de la solicitud', function () {
    $s = changeScenario();

    $request = askChange($s);

    expect(Activity::query()->where('subject_id', $request->id)->where('event', 'requested')->exists())->toBeTrue();
});

it('avisa a los jefes del departamento del grupo y a nadie más', function () {
    Notification::fake();
    $s = changeScenario();
    $otro = changeScenario();

    $request = askChange($s);

    Notification::assertSentTo($s['jefe'], ChangeRequested::class, fn ($n) => $n->message($s['jefe'])->parameters === ['solicitud' => $request->id, 'campus' => $s['group']->campus->code]);
    Notification::assertNotSentTo($otro['jefe'], ChangeRequested::class);
    Notification::assertNotSentTo($s['coordinador'], ChangeRequested::class);
});

it('exige la carga en asignación o ajustes', function (LoadStatus $status, bool $allowed) {
    $s = changeScenario($status);

    if ($allowed) {
        expect(askChange($s)->exists)->toBeTrue();

        return;
    }

    expect(fn () => askChange($s))->toThrow(ValidationException::class, 'La carga del departamento está en');
})->with([
    'borrador' => [LoadStatus::Draft, false],
    'propuesta' => [LoadStatus::Proposed, false],
    'asignación' => [LoadStatus::Staffing, true],
    'ajustes' => [LoadStatus::Adjusting, true],
    'cerrada' => [LoadStatus::Closed, false],
    'publicada' => [LoadStatus::Published, false],
]);

it('rechaza un grupo cancelado', function () {
    $s = changeScenario();
    $s['group']->update(['status' => GroupStatus::Cancelled]);

    expect(fn () => askChange($s))->toThrow(ValidationException::class, 'cancelado');
});

it('rechaza un motivo o una descripción en blanco', function () {
    $s = changeScenario();

    expect(fn () => app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Room, 'Salón B', ' '))->toThrow(ValidationException::class)
        ->and(fn () => app(RequestChange::class)->handle($s['group'], $s['teacherUser'], ChangeRequestKind::Room, ' ', 'Motivo'))->toThrow(ValidationException::class);
});

it('decisión 3: una sola abierta por docente y grupo, en otro grupo sí, y tras rechazar sí', function () {
    $s = changeScenario();
    $first = askChange($s);

    expect(fn () => askChange($s))->toThrow(ValidationException::class, 'Ya tienes una solicitud abierta para este grupo.');

    $other = Group::factory()->create([
        'term_id' => $s['group']->term_id, 'campus_id' => $s['group']->campus_id, 'subject_id' => $s['group']->subject_id, 'teacher_id' => $s['teacher']->id,
    ]);
    expect(app(RequestChange::class)->handle($other, $s['teacherUser'], ChangeRequestKind::Room, 'Salón B', 'Motivo')->exists)->toBeTrue();

    $first->update(['status' => ChangeRequestStatus::Rejected, 'decision_comment' => 'No procede']);

    expect(askChange($s)->exists)->toBeTrue();
});
