<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\SetGroupCapacity;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| SetGroupCapacity (re-análisis, tarea 8.3a): la capacidad de un grupo sube dentro del salón más chico de sus sesiones
| (HC-05) y no baja de lo repartido y lo apartado por sus propuestas (HC-07). Solo con la carga editable.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->group = Group::factory()->withQuota(30)->create([
        'code' => 'A',
        'capacity' => 40,
        'subject_id' => Subject::factory()->create(['department_id' => $this->department->id])->id,
    ]);
    $building = Building::factory()->create(['campus_id' => $this->group->campus_id]);
    GroupSession::factory()->for($this->group)->inRoom(Room::factory()->capacity(50)->create(['building_id' => $building->id, 'code' => 'A-101']))->on(1, '07:00', '09:00')->create();
    GroupSession::factory()->for($this->group)->inRoom(Room::factory()->capacity(45)->create(['building_id' => $building->id, 'code' => 'A-102']))->on(3, '07:00', '09:00')->create();
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Reanalysis)->create(['term_id' => $this->group->term_id, 'modality_id' => $this->group->modality_id, 'department_id' => $this->department->id]);
});

function setCapacityErrors(Group $group, int $capacity, User $actor): array
{
    try {
        app(SetGroupCapacity::class)->handle($group, $capacity, $actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('HC-05: sube la capacidad hasta el salón más chico de sus sesiones, con bitácora y aviso', function () {
    Event::fake([GroupScheduleChanged::class]);

    app(SetGroupCapacity::class)->handle($this->group, 45, $this->actor);

    $activity = Activity::query()->where('event', 'capacity_updated')->sole();

    expect($this->group->fresh()->capacity)->toBe(45)
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->attribute_changes['old']['capacity'])->toBe(40)
        ->and($activity->attribute_changes['attributes']['capacity'])->toBe(45);
    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->group->is($this->group) && ! $event->timesChanged);
});

it('HC-05: no sube más que el salón más chico de sus sesiones', function () {
    expect(setCapacityErrors($this->group, 46, $this->actor)['capacity'][0])->toBe('HC-05: el salón A-102 tiene 45 lugares; la capacidad del grupo A no sube de ahí.')
        ->and($this->group->fresh()->capacity)->toBe(40);
});

it('HC-07: no baja de lo repartido y lo apartado por sus propuestas', function () {
    InterdepartmentalAgreement::factory()->sharedGroup(5)->create(['group_id' => $this->group->id, 'term_id' => $this->group->term_id]);

    expect(setCapacityErrors($this->group, 34, $this->actor)['capacity'][0])->toBe('HC-07: el grupo A tiene 30 lugares repartidos y 5 apartados; la capacidad no baja de 35.');

    app(SetGroupCapacity::class)->handle($this->group, 35, $this->actor);

    expect($this->group->fresh()->capacity)->toBe(35);
});

it('va de 1 a 999 y no edita con la carga fuera de edición', function () {
    expect(setCapacityErrors($this->group, 0, $this->actor))->toHaveKey('capacity');

    $this->load->update(['status' => LoadStatus::Published]);

    expect(setCapacityErrors($this->group, 42, $this->actor))->toHaveKey('capacity')
        ->and($this->group->fresh()->capacity)->toBe(40);
});

it('sin cambios no escribe ni registra', function () {
    Event::fake([GroupScheduleChanged::class]);

    app(SetGroupCapacity::class)->handle($this->group, 40, $this->actor);

    expect(Activity::query()->where('event', 'capacity_updated')->count())->toBe(0);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});
