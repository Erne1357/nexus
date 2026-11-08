<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CancelRoomLoan (tarea 3.7, HC-10): quien pidió cancela el préstamo y el salón se quita de las sesiones que cubría.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->from = Department::factory()->create();
    $this->to = Department::factory()->create();
    $this->group = Group::factory()->create(['code' => 'D', 'subject_id' => Subject::factory()->create(['department_id' => $this->from->id])->id]);
    $this->room = Room::factory()->create(['building_id' => Building::factory()->create(['campus_id' => $this->group->campus_id])->id, 'department_id' => $this->to->id]);
    $this->loan = InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id, 'room_id' => $this->room->id,
        'day' => 1, 'starts_at' => '09:00', 'ends_at' => '11:00', 'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id,
    ]);
});

it('cancela, quita el salón solo de lo cubierto, deja bitácora, avisa y da cuántas sesiones', function () {
    Event::fake([GroupScheduleChanged::class]);
    $covered = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();
    $other = GroupSession::factory()->for($this->group)->on(2, '09:00', '10:00')->inRoom($this->room)->create();

    $cleared = app(CancelRoomLoan::class)->handle($this->loan, $this->actor);

    expect($cleared)->toBe(1)
        ->and($this->loan->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($covered->fresh()->room_id)->toBeNull()
        ->and($other->fresh()->room_id)->toBe($this->room->id)
        ->and(Activity::query()->where('event', 'room_removed_by_cancelled_loan')->sole()->subject_id)->toBe($this->group->id);
    Event::assertDispatched(GroupScheduleChanged::class);
});

it('no cancela un grupo compartido ni un acuerdo ya cancelado', function () {
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create(['from_department_id' => $this->from->id, 'to_department_id' => $this->to->id]);
    $cancelled = InterdepartmentalAgreement::factory()->inStatus(AgreementStatus::Cancelled)->create(['group_id' => $this->group->id, 'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id]);

    expect(fn () => app(CancelRoomLoan::class)->handle($shared, $this->actor))->toThrow(ValidationException::class)
        ->and(fn () => app(CancelRoomLoan::class)->handle($cancelled, $this->actor))->toThrow(ValidationException::class);
});
