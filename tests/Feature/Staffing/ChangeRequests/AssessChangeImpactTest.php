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
use Illuminate\Support\Facades\DB;
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

/**
 * El grupo con dos sesiones en el mismo salón (martes y jueves de 7 a 9) y otro grupo con una sesión que ya choca con
 * una de ellas en ese salón (la base solo deja el choque con la restricción diferida).
 */
function impactRoomClashWorld(array $s, int $clashDay): void
{
    $room = impactRoom($s);
    GroupSession::factory()->on(2, '07:00', '09:00')->inRoom($room)->create(['group_id' => $s['group']->id]);
    GroupSession::factory()->on(4, '07:00', '09:00')->inRoom($room)->create(['group_id' => $s['group']->id]);
    $clash = GroupSession::factory()->on($clashDay, '08:00', '10:00')->create(['group_id' => impactOtherGroup($s, ['code' => 'B'])->id]);
    DB::statement('set constraints group_sessions_room_no_overlap deferred');
    DB::table('group_sessions')->where('id', $clash->id)->update(['room_id' => $room->id]);
}

it('un choque que el grupo ya tenía y que la propuesta no toca no cuenta', function () {
    $s = changeScenario();
    impactRoomClashWorld($s, 2);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'],
    ]])->fresh()->impact;

    expect(array_column($impact['hard'], 'rule'))->not->toContain('HC-02');
});

it('un choque que el cambio quita no cuenta en negativo ni deja nada', function () {
    $s = changeScenario();
    impactRoomClashWorld($s, 4);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'],
    ]])->fresh()->impact;

    expect(array_column($impact['hard'], 'rule'))->not->toContain('HC-02')->and($impact['affected_groups'])->toBe([]);
});

it('el mismo choque se cuenta una vez', function () {
    $s = changeScenario();
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $s['group']->id]);
    $other = impactOtherGroup($s, ['teacher_id' => $s['teacher']->id, 'code' => 'B']);
    GroupSession::factory()->on(3, '09:00', '11:00')->create(['group_id' => $other->id]);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 3, 'starts_at' => '10:00', 'ends_at' => '12:00']]])->fresh()->impact;
    $hc01 = collect($impact['hard'])->firstWhere('rule', 'HC-01');

    expect($hc01['messages'])->toHaveCount(1);
});

it('varias sesiones propuestas con choque en cada una cuentan los grupos de todas', function () {
    $s = changeScenario();
    impactRoomClashWorld($s, 5);
    $room = Room::query()->where('code', 'A-1')->sole();
    $third = GroupSession::factory()->on(3, '08:00', '10:00')->create(['group_id' => impactOtherGroup($s, ['code' => 'D'])->id]);
    DB::table('group_sessions')->where('id', $third->id)->update(['room_id' => $room->id]);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ['day' => 5, 'starts_at' => '07:00', 'ends_at' => '09:00'],
    ]])->fresh()->impact;

    expect(array_column($impact['affected_groups'], 'code'))->toBe(['B', 'D']);
});

it('rechaza sesiones propuestas que se empalman entre sí', function () {
    $s = changeScenario();

    impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ['day' => 3, 'starts_at' => '08:00', 'ends_at' => '10:00'],
    ]]);
})->throws(ValidationException::class, 'empalmarse entre sí');

it('con menos sesiones que el grupo, las de más se quitan y su choque desaparece', function () {
    $s = changeScenario();
    impactRoomClashWorld($s, 4);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00']]])->fresh()->impact;

    expect(array_column($impact['hard'], 'rule'))->not->toContain('HC-02');
});

it('HC-12: cambiar el total de horas semanales del grupo cuenta como choque duro', function () {
    $s = changeScenario();
    GroupSession::factory()->on(1, '07:00', '10:00')->create(['group_id' => $s['group']->id]);
    GroupSession::factory()->on(3, '07:00', '09:00')->create(['group_id' => $s['group']->id]);

    $impact = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 1, 'starts_at' => '07:00', 'ends_at' => '10:00'],
        ['day' => 3, 'starts_at' => '07:00', 'ends_at' => '08:00'],
    ]])->fresh()->impact;

    expect(array_column($impact['hard'], 'rule'))->toContain('HC-12');
});

it('rechaza sesiones propuestas fuera de los días o los bloques de la modalidad', function (array $session) {
    $s = changeScenario();

    impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [$session]]);
})->with([
    'el día 7' => [['day' => 7, 'starts_at' => '07:00', 'ends_at' => '09:00']],
    'fuera de bloque' => [['day' => 2, 'starts_at' => '07:10', 'ends_at' => '09:00']],
    'antes de la jornada' => [['day' => 2, 'starts_at' => '04:00', 'ends_at' => '06:00']],
])->throws(ValidationException::class);

it('empareja la propuesta con la foto de la solicitud y conserva el salón de su sesión', function () {
    $s = changeScenario();
    $room = impactRoom($s);
    GroupSession::factory()->on(4, '07:00', '09:00')->inRoom($room)->create(['group_id' => $s['group']->id]);
    GroupSession::factory()->on(2, '07:00', '09:00')->create(['group_id' => $s['group']->id]);
    $other = impactOtherGroup($s, ['code' => 'C']);
    GroupSession::factory()->on(5, '09:00', '11:00')->inRoom($room)->create(['group_id' => $other->id]);
    $request = impactRequest($s, ChangeRequestKind::Schedule, ['sessions' => [
        ['day' => 2, 'starts_at' => '07:00', 'ends_at' => '09:00'],
        ['day' => 5, 'starts_at' => '10:00', 'ends_at' => '12:00'],
    ]]);

    expect(array_column($request->fresh()->impact['hard'], 'rule'))->toContain('HC-02');
});
