<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Demand\Models\DemandForecast;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Actions\OpenReanalysisGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\Reanalysis\NewGroupPlacement;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;
use Tests\Support\Scheduling\ReanalysisWorld;

/*
| OpenReanalysisGroup (tarea 8.3b): aplicar «Abrir un grupo» crea el grupo nuevo con su cupo y sus sesiones en el horario
| y el salón del motor, en una transacción, con la carga en re-análisis y el jefe del departamento (RA-07), y revisa con
| los candados puestos que nada haya cambiado. El mundo: Cálculo Integral en C2 con ISIC faltante (120 esperados contra
| el grupo A de 40 lugares).
*/

beforeEach(function () {
    $this->world = ReanalysisWorld::create();
    $this->jefe = $this->world->head();
    $this->world->published()->gradesArrived()->inReanalysis($this->jefe);
    DemandForecast::query()->where('subject_id', $this->world->integral->id)->where('campus_id', $this->world->c2->id)->where('career_id', $this->world->isc->id)->update(['expected_students' => 120]);
    $this->suggestion = $this->world->openGroupSuggestion();
    $this->placement = $this->world->newGroupPlacement($this->suggestion);
});

/**
 * @return array<string, list<string>>
 */
function openReanalysisGroupErrors(ReanalysisWorld $world, User $actor, mixed $suggestion, NewGroupPlacement $placement): array
{
    try {
        app(OpenReanalysisGroup::class)->handle($world->load->fresh(), $suggestion, $placement, $actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

function newReanalysisGroups(ReanalysisWorld $world): int
{
    return Group::query()->where('subject_id', $world->integral->id)->where('campus_id', $world->c2->id)->count();
}

it('crea el grupo suelto y sin docente, con el cupo de la carrera y sus sesiones en el salón del motor, con su bitácora', function () {
    Event::fake([GroupsCreated::class]);

    $group = app(OpenReanalysisGroup::class)->handle($this->world->load, $this->suggestion, $this->placement, $this->jefe);

    $sessions = GroupSession::query()->where('group_id', $group->id)->orderBy('day')->get();

    expect($group->subject_id)->toBe($this->world->integral->id)
        ->and($group->campus_id)->toBe($this->world->c2->id)
        ->and($group->owner_career_id)->toBe($this->world->isc->id)
        ->and($group->semester)->toBe(2)
        ->and($group->capacity)->toBe(35)
        ->and($group->status)->toBe(GroupStatus::Planned)
        ->and($group->teacher_id)->toBeNull()
        ->and($group->package_id)->toBeNull()
        ->and($group->proposal_item_id)->toBeNull()
        ->and($group->code)->toBe('C')
        ->and(GroupQuota::query()->where('group_id', $group->id)->get(['career_id', 'max_seats', 'is_owner'])->toArray())
        ->toBe([['career_id' => $this->world->isc->id, 'max_seats' => 35, 'is_owner' => true]])
        ->and($sessions->map(fn (GroupSession $session): array => [$session->day, substr($session->starts_at, 0, 5), substr($session->ends_at, 0, 5), $session->room_id])->all())
        ->toBe(array_map(fn (array $session): array => [$session['day'], $session['startsAt'], $session['endsAt'], $session['roomId']], $this->placement->sessions))
        ->and(Activity::query()->where('subject_id', $group->id)->where('event', 'created')->sole()->causer_id)->toBe($this->jefe->id)
        ->and(Activity::query()->where('subject_id', $group->id)->where('event', 'sessions_replaced')->sole()->causer_id)->toBe($this->jefe->id);

    Event::assertDispatched(GroupsCreated::class, fn (GroupsCreated $event): bool => $event->groupIds === [$group->id]);
});

it('aplicada dos veces seguidas (dos pestañas) solo abre un grupo', function () {
    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement))->toBe([])
        ->and(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement))->toBe(['suggestion' => ['Esto cambió desde que abriste las sugerencias; vuelve a abrirlas.']])
        ->and(newReanalysisGroups($this->world))->toBe(3);
});

it('no se aplica si otro grupo de la materia cambió de capacidad o de cupos', function () {
    $this->world->groups['A']->update(['capacity' => 50]);

    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement))->toBe(['suggestion' => ['Esto cambió desde que abriste las sugerencias; vuelve a abrirlas.']])
        ->and(newReanalysisGroups($this->world))->toBe(2);
});

it('HC-02 y HC-11: si el salón sugerido ya se ocupó o se bloqueó a esa hora, no crea nada y dice qué cambió', function () {
    $session = $this->placement->sessions[0];
    $code = $this->placement->roomCode($session['roomId']);
    $other = $this->world->group($this->world->differential, $this->world->iind, 20, $this->world->c2, 1, day: 5);
    GroupSession::factory()->for($other)->inRoom(Room::query()->whereKey($session['roomId'])->sole())->on($session['day'], $session['startsAt'], $session['endsAt'])->create();

    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement)['suggestion'][0])
        ->toStartWith("Esto cambió desde que buscaste el lugar: {$code} ya está ocupado (HC-02) el lunes")
        ->toEndWith('Vuelve a buscar horario y salón.')
        ->and(newReanalysisGroups($this->world))->toBe(2);

    GroupSession::query()->where('group_id', $other->id)->where('day', $session['day'])->delete();
    RoomBlock::factory()->create(['room_id' => $session['roomId'], 'day' => $session['day'], 'starts_at' => $session['startsAt'], 'ends_at' => $session['endsAt']]);

    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement)['suggestion'][0])->toContain('está bloqueado (HC-11)');
});

it('no se aplica con la carga fuera de re-análisis', function () {
    $this->world->load->update(['status' => LoadStatus::Closed]);

    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, $this->placement))->toBe(['suggestion' => ['La carga ya no está en re-análisis: las sugerencias ya no se aplican.']])
        ->and(newReanalysisGroups($this->world))->toBe(2);
});

it('sin lugar del motor no hay qué aplicar', function () {
    expect(openReanalysisGroupErrors($this->world, $this->jefe, $this->suggestion, new NewGroupPlacement([], reasons: ['HC-05: no cabe.'])))
        ->toBe(['suggestion' => ['El motor no le encontró lugar al grupo nuevo: no hay qué aplicar.']]);
});

it('RA-07: el coordinador, Subdirección y el jefe de otro departamento no abren el grupo', function (string $who) {
    $actor = match ($who) {
        'coordinador' => $this->world->coordinator($this->world->isc),
        'subdireccion' => User::factory()->withRole(Role::Subdireccion)->create(),
        'otro jefe' => User::factory()->withRole(Role::JefeDepartamento, ['department_id' => Department::factory()->create()->id])->create(),
    };

    expect(fn () => app(OpenReanalysisGroup::class)->handle($this->world->load, $this->suggestion, $this->placement, $actor))->toThrow(AuthorizationException::class)
        ->and(newReanalysisGroups($this->world))->toBe(2);
})->with(['coordinador', 'subdireccion', 'otro jefe']);
