<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Agreements\RejectRoomLoan;
use App\Modules\Scheduling\Actions\Agreements\RejectSharedGroup;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| RejectRoomLoan y RejectSharedGroup (tarea 3.7, HC-10): rechazar un préstamo quita el salón de las sesiones del grupo
| que cubre el préstamo; rechazar un grupo compartido solo rechaza el acuerdo.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->from = Department::factory()->create();
    $this->to = Department::factory()->create();
    $this->group = Group::factory()->create(['code' => 'D', 'subject_id' => Subject::factory()->create(['department_id' => $this->from->id])->id]);
    $building = Building::factory()->create(['campus_id' => $this->group->campus_id]);
    $this->room = Room::factory()->create(['building_id' => $building->id, 'department_id' => $this->to->id]);
    $this->other = Room::factory()->create(['building_id' => $building->id, 'department_id' => $this->to->id]);
    $this->loan = InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'room_id' => $this->room->id, 'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00',
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id,
    ]);
});

it('rechaza con motivo y quita el salón solo de las sesiones que cubre el préstamo', function () {
    $covered = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();
    $partly = GroupSession::factory()->for($this->group)->on(1, '10:30', '12:00')->inRoom($this->room)->create();
    $otherDay = GroupSession::factory()->for($this->group)->on(2, '09:00', '10:00')->inRoom($this->room)->create();
    $afterwards = GroupSession::factory()->for($this->group)->on(1, '12:00', '13:00')->inRoom($this->room)->create();
    $otherRoom = GroupSession::factory()->for($this->group)->on(3, '09:00', '10:00')->inRoom($this->other)->create();
    $otherGroup = GroupSession::factory()->for(Group::factory()->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id]))->on(4, '09:00', '10:00')->inRoom($this->room)->create();

    $cleared = app(RejectRoomLoan::class)->handle($this->loan, 'Ese bloque ya lo usa otro grupo.', $this->actor);

    expect($cleared)->toBe(2)
        ->and($this->loan->fresh()->status)->toBe(AgreementStatus::Rejected)
        ->and($this->loan->fresh()->comment)->toBe('Ese bloque ya lo usa otro grupo.')
        ->and($covered->fresh()->room_id)->toBeNull()
        ->and($partly->fresh()->room_id)->toBeNull()
        ->and($otherDay->fresh()->room_id)->toBe($this->room->id)
        ->and($afterwards->fresh()->room_id)->toBe($this->room->id)
        ->and($otherRoom->fresh()->room_id)->toBe($this->other->id)
        ->and($otherGroup->fresh()->room_id)->toBe($this->room->id);
});

it('deja bitácora en el grupo y avisa después del commit', function () {
    Event::fake([GroupScheduleChanged::class]);
    $session = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();

    app(RejectRoomLoan::class)->handle($this->loan, 'No hay lugar.', $this->actor);

    $entry = Activity::query()->where('event', 'room_removed_by_rejected_loan')->sole();
    expect($entry->subject_id)->toBe($this->group->id)
        ->and($entry->causer_id)->toBe($this->actor->id)
        ->and($entry->properties['sessions'])->toBe([$session->id]);
    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->group->is($this->group));
});

it('sin sesiones cubiertas da 0, no deja bitácora de salón y avisa igual del cambio', function () {
    Event::fake([GroupScheduleChanged::class]);

    expect(app(RejectRoomLoan::class)->handle($this->loan, 'No hay.', $this->actor))->toBe(0)
        ->and(Activity::query()->where('event', 'room_removed_by_rejected_loan')->exists())->toBeFalse();
    Event::assertDispatched(GroupScheduleChanged::class);
});

it('sin motivo no rechaza, no quita el salón y no avisa', function () {
    Event::fake([GroupScheduleChanged::class]);
    $session = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();

    expect(fn () => app(RejectRoomLoan::class)->handle($this->loan, '  ', $this->actor))->toThrow(ValidationException::class);

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and($session->fresh()->room_id)->toBe($this->room->id);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('rechazar un grupo compartido solo rechaza el acuerdo; en un préstamo no sirve', function () {
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create(['from_department_id' => $this->from->id, 'to_department_id' => $this->to->id]);

    app(RejectSharedGroup::class)->handle($shared, 'No tenemos lugares.', $this->actor);

    expect($shared->fresh()->status)->toBe(AgreementStatus::Rejected)
        ->and(fn () => app(RejectSharedGroup::class)->handle($this->loan, 'x', $this->actor))->toThrow(ValidationException::class)
        ->and(fn () => app(RejectRoomLoan::class)->handle($shared, 'x', $this->actor))->toThrow(ValidationException::class);
});

it('si el departamento al que va dirigido ya no es dueño del salón, no rechaza ni quita el salón', function () {
    $session = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();
    $this->room->update(['department_id' => Department::factory()->create()->id]);

    expect(fn () => app(RejectRoomLoan::class)->handle($this->loan, 'No.', $this->actor))->toThrow(ValidationException::class, 'ya no es de tu departamento');

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed)
        ->and($session->fresh()->room_id)->toBe($this->room->id);
});
