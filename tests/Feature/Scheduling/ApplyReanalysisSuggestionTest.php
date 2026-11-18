<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\ApplyReanalysisSuggestion;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Queries\ReanalysisBoardQuery;
use App\Modules\Scheduling\Queries\ReanalysisSuggestionsQuery;
use App\Modules\Scheduling\Rules\Reanalysis\ReanalysisSuggestion;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| ApplyReanalysisSuggestion (tarea 8.3a): cada sugerencia se aplica con las Actions de la fase 3 en una sola transacción,
| con la carga en re-análisis y el jefe del departamento (RA-07). El mundo: Cálculo Integral en C2 con ISIC faltante (70
| esperados; el grupo A de 40 lugares en un salón de 50) y en C1 con ISIC sobrante (30 contra el grupo D de 45).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 70]);
    $this->isc = $this->world->isc->id;
});

function reanalysisSuggestionIn(ReanalysisWorld $world, Subject $subject, Campus $campus, string $key): ReanalysisSuggestion
{
    $block = app(ReanalysisBoardQuery::class)->block($world->load->fresh(), null, $subject->id, $campus->id);
    $suggestion = app(ReanalysisSuggestionsQuery::class)->for($world->load, $block)->find($key);

    expect($suggestion)->not->toBeNull();

    return $suggestion;
}

function applyReanalysisErrors(ReanalysisWorld $world, ReanalysisSuggestion $suggestion, User $actor, ?int $seats = null): array
{
    try {
        app(ApplyReanalysisSuggestion::class)->handle($world->load->fresh(), $suggestion, $actor, $seats);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

/**
 * @return array<string, int>
 */
function reanalysisQuotasOf(Group $group): array
{
    return GroupQuota::query()->where('group_id', $group->id)->pluck('max_seats', 'career_id')->all();
}

function reanalysisLargeRoom(ReanalysisWorld $world, string $code = 'L-70', int $capacity = 70): Room
{
    return Room::factory()->capacity($capacity)->create(['building_id' => Building::factory()->create(['campus_id' => $world->c2->id])->id, 'code' => $code]);
}

it('«Ampliar cupo»: HC-05 y HC-07, sube la capacidad dentro del salón y el cupo de la carrera, con la bitácora de cada Action', function () {
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "expand_quota:{$a->id}:{$this->isc}");

    app(ApplyReanalysisSuggestion::class)->handle($this->world->load, $suggestion, $this->jefe);

    expect($a->fresh()->capacity)->toBe(50)
        ->and(reanalysisQuotasOf($a))->toBe([$this->isc => 50])
        ->and(Activity::query()->where('event', 'capacity_updated')->sole()->causer_id)->toBe($this->jefe->id)
        ->and(Activity::query()->where('event', 'quotas_updated')->sole()->causer_id)->toBe($this->jefe->id);
});

it('«Ampliar cupo» con los lugares que editó el jefe, de 1 al máximo de la sugerencia', function () {
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "expand_quota:{$a->id}:{$this->isc}");

    expect(applyReanalysisErrors($this->world, $suggestion, $this->jefe, 11))->toBe(['seats' => ['Los lugares van de 1 a 10.']])
        ->and(applyReanalysisErrors($this->world, $suggestion, $this->jefe, 0))->toHaveKey('seats')
        ->and(applyReanalysisErrors($this->world, $suggestion, $this->jefe, 4))->toBe([])
        ->and($a->fresh()->capacity)->toBe(44)
        ->and(reanalysisQuotasOf($a))->toBe([$this->isc => 44]);
});

it('«Cambiar salón»: mueve la sesión al salón libre, sube la capacidad y el cupo', function () {
    $room = reanalysisLargeRoom($this->world);
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "change_room:{$a->id}:{$this->isc}");

    app(ApplyReanalysisSuggestion::class)->handle($this->world->load, $suggestion, $this->jefe);

    expect(GroupSession::query()->where('group_id', $a->id)->value('room_id'))->toBe($room->id)
        ->and($a->fresh()->capacity)->toBe(60)
        ->and(reanalysisQuotasOf($a))->toBe([$this->isc => 60])
        ->and(Activity::query()->where('event', 'session_room_assigned')->count())->toBe(1);
});

it('HC-02: si el salón ya se ocupó a esa hora, no aplica nada', function () {
    $room = reanalysisLargeRoom($this->world);
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "change_room:{$a->id}:{$this->isc}");
    $other = $this->world->group($this->world->differential, $this->world->iind, 20, $this->world->c2, 1, day: 4);
    GroupSession::factory()->for($other)->inRoom($room)->on(1, '08:00', '10:00')->create();
    $before = GroupSession::query()->where('group_id', $a->id)->value('room_id');

    expect(applyReanalysisErrors($this->world, $suggestion, $this->jefe))->toHaveKey('session')
        ->and(GroupSession::query()->where('group_id', $a->id)->value('room_id'))->toBe($before)
        ->and($a->fresh()->capacity)->toBe(40)
        ->and(reanalysisQuotasOf($a))->toBe([$this->isc => 40]);
});

it('«Cerrar grupo»: reubica los lugares que hacen falta y cancela el grupo', function () {
    $d = $this->world->groups['D'];
    $d->update(['capacity' => 50]);
    $e = $this->world->group($this->world->integral, $this->world->isc, 40, $this->world->c1, 2, day: 2);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c1->id)->update(['expected_students' => 50]);
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c1, "close_group:{$e->id}");

    app(ApplyReanalysisSuggestion::class)->handle($this->world->load, $suggestion, $this->jefe);

    expect($e->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and(reanalysisQuotasOf($d))->toBe([$this->isc => 50])
        ->and(Activity::query()->where('event', 'cancelled')->where('subject_id', $e->id)->exists())->toBeTrue();
});

it('no se aplica con la carga fuera de re-análisis, ni «Abrir grupo»', function () {
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "expand_quota:{$a->id}:{$this->isc}");
    $open = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, 'open_group');

    expect(applyReanalysisErrors($this->world, $open, $this->jefe))->toBe(['suggestion' => ['Abrir un grupo llega con la sugerencia del motor: aquí todavía no se aplica.']]);

    $this->world->load->update(['status' => LoadStatus::Closed]);

    expect(applyReanalysisErrors($this->world, $suggestion, $this->jefe))->toBe(['suggestion' => ['La carga ya no está en re-análisis: las sugerencias ya no se aplican.']])
        ->and($a->fresh()->capacity)->toBe(40);
});

it('RA-07: el coordinador, Subdirección y el jefe de otro departamento no aplican', function (string $who) {
    $a = $this->world->groups['A'];
    $suggestion = reanalysisSuggestionIn($this->world, $this->world->integral, $this->world->c2, "expand_quota:{$a->id}:{$this->isc}");
    $user = match ($who) {
        'coordinador' => $this->world->coordinator($this->world->isc),
        'subdireccion' => User::factory()->withRole(Role::Subdireccion)->create(),
        'otro jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create(),
    };

    expect(fn () => app(ApplyReanalysisSuggestion::class)->handle($this->world->load, $suggestion, $user))->toThrow(AuthorizationException::class)
        ->and($a->fresh()->capacity)->toBe(40);
})->with(['coordinador', 'subdireccion', 'otro jefe']);
