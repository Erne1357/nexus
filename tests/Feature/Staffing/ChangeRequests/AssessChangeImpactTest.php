<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Staffing\Actions\ChangeRequests\AssessChangeImpact;
use App\Modules\Staffing\Actions\ChangeRequests\RequestChange;
use App\Modules\Staffing\Enums\ChangeRequestKind;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

require_once __DIR__.'/ChangeRequestsTestSupport.php';

/*
| AssessChangeImpact (CR-01, tarea 8.6): simula lo que propone una solicitud de cambio con el validador, sin escribir
| sesiones, y deja el impacto en `change_requests.impact`: choques duros y blandos por regla, grupos y docentes
| afectados. Un cambio de horario choca con el docente (HC-01) o con el salón (HC-02); uno de salón, con el ocupante.
*/

function impactRoom(array $s, string $code = 'A-1'): Room
{
    return Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $s['group']->campus_id])->id, 'code' => $code]);
}

function impactOtherGroup(array $s, array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => $s['group']->term_id,
        'campus_id' => $s['group']->campus_id,
        'modality_id' => $s['group']->modality_id,
        'subject_id' => Subject::factory()->create(['name' => 'Programación Web'])->id,
        ...$attributes,
    ]);
}

function impactRequest(array $s, ChangeRequestKind $kind, array $structured, ?User $actor = null)
{
    return app(RequestChange::class)->handle($s['group'], $actor ?? $s['teacherUser'], $kind, 'El cambio', 'Un motivo', $structured);
}

it('un cambio de horario que choca con otro grupo del docente da HC-01 con los grupos y docentes afectados', function () {
    $s = changeScenario();
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $s['group']->id]);
    $other = impactOtherGroup($s, ['teacher_id' => $s['teacher']->id, 'code' => 'B']);
    GroupSession::factory()->on(3, '09:00', '11:00')->create(['group_id' => $other->id]);

    $request = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00']]]);
    $impact = $request->fresh()->impact;

    expect($impact['assessable'])->toBeTrue()
        ->and(array_column($impact['hard'], 'rule'))->toContain('HC-01')
        ->and($impact['affected_groups'])->toHaveCount(1)
        ->and($impact['affected_groups'][0])->toMatchArray(['code' => 'B', 'subject' => 'Programación Web'])
        ->and($impact['affected_teachers'][0]['name'])->toBe($s['teacher']->name)
        ->and($impact['assessed_at'])->not->toBeNull();
});

it('un cambio de horario que choca con el salón da HC-02', function () {
    $s = changeScenario();
    $room = impactRoom($s);
    GroupSession::factory()->on(2, '07:00', '09:00')->inRoom($room)->create(['group_id' => $s['group']->id]);
    $other = impactOtherGroup($s, ['code' => 'C']);
    GroupSession::factory()->on(3, '09:00', '11:00')->inRoom($room)->create(['group_id' => $other->id]);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00']]])->fresh()->impact;

    expect(array_column($impact['hard'], 'rule'))->toContain('HC-02')
        ->and(array_column($impact['affected_groups'], 'code'))->toBe(['C']);
});

it('un cambio de horario sin choques deja el impacto sin violaciones duras ni grupos afectados', function () {
    $s = changeScenario();
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $s['group']->id]);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00']]])->fresh()->impact;

    expect($impact['assessable'])->toBeTrue()
        ->and($impact['hard'])->toBe([])
        ->and($impact['affected_groups'])->toBe([]);
});

it('un cambio de salón a uno ocupado da HC-02', function () {
    $s = changeScenario();
    $room = impactRoom($s);
    GroupSession::factory()->on(3, '09:00', '11:00')->create(['group_id' => $s['group']->id]);
    $other = impactOtherGroup($s, ['code' => 'C']);
    GroupSession::factory()->on(3, '09:00', '11:00')->inRoom($room)->create(['group_id' => $other->id]);

    $request = impactRequest($s, ChangeRequestKind::Room, ['room' => 'a-1']);
    $impact = $request->fresh()->impact;

    expect($request->proposed['room'])->toBe('A-1')
        ->and(array_column($impact['hard'], 'rule'))->toContain('HC-02')
        ->and(array_column($impact['affected_groups'], 'code'))->toBe(['C']);
});

it('no escribe sesiones al simular', function () {
    $s = changeScenario();
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $s['group']->id]);

    impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 4, 'starts_at' => '10:00', 'ends_at' => '12:00']]]);

    expect(GroupSession::query()->where('group_id', $s['group']->id)->sole()->day)->toBe(2);
});

it('una solicitud solo con texto libre no se puede simular y lo dice', function () {
    $s = changeScenario();

    $impact = impactRequest($s, ChangeRequestKind::Schedule, [])->fresh()->impact;

    expect($impact['assessable'])->toBeFalse()->and($impact['hard'])->toBe([]);
});

it('rechaza un salón que no existe en el campus del grupo', function () {
    $s = changeScenario();

    impactRequest($s, ChangeRequestKind::Room, ['room' => 'NO-EXISTE']);
})->throws(ValidationException::class);

it('recalcular guarda el impacto y lo deja en la bitácora', function () {
    $s = changeScenario();
    $request = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00']]]);
    $request->update(['impact' => null]);

    app(AssessChangeImpact::class)->handle($request->fresh());

    expect($request->fresh()->impact['assessed_at'])->not->toBeNull()
        ->and(Activity::query()->where('subject_id', $request->id)->where('event', 'impact_assessed')->count())->toBe(2);
});

it('el impacto queda viejo cuando el horario de la modalidad cambia después de calcularlo', function () {
    $s = changeScenario();
    $request = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 4, 'starts_at' => '07:00', 'ends_at' => '09:00']]]);
    $assess = app(AssessChangeImpact::class);

    expect($assess->isStale($request->fresh()))->toBeFalse();

    $this->travel(1)->minutes();
    GroupSession::factory()->on(5, '07:00', '09:00')->create(['group_id' => impactOtherGroup($s)->id]);

    expect($assess->isStale($request->fresh()))->toBeTrue();
});
