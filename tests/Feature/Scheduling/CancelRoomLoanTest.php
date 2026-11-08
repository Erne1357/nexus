<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Agreements\CancelRoomLoan;
use App\Modules\Scheduling\Actions\Quotas\AcceptSharedGroup;
use App\Modules\Scheduling\Actions\Quotas\WithdrawGroupQuotaProposal;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
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
    AcademicLoad::factory()->inStatus(LoadStatus::Adjusting)->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'department_id' => $this->from->id]);
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

it('la carrera: aceptado entre la lectura y la cancelación da un error claro y no toca el salón', function () {
    $session = GroupSession::factory()->for($this->group)->on(1, '09:00', '10:00')->inRoom($this->room)->create();
    $stale = InterdepartmentalAgreement::query()->find($this->loan->id);
    InterdepartmentalAgreement::query()->whereKey($this->loan->id)->update(['status' => AgreementStatus::Accepted, 'decided_by' => $this->actor->id, 'decided_at' => now()]);

    expect(fn () => app(CancelRoomLoan::class)->handle($stale, $this->actor))->toThrow(ValidationException::class, 'ya se decidió');

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted)
        ->and($session->fresh()->room_id)->toBe($this->room->id);
});

it('la carrera en un grupo compartido: aceptado entre la lectura y retirar no deja cupo huérfano', function () {
    $shared = InterdepartmentalAgreement::factory()->sharedGroup(5)->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id,
    ]);
    $stale = InterdepartmentalAgreement::query()->find($shared->id);
    app(AcceptSharedGroup::class)->handle($shared, $this->actor);

    expect(fn () => app(WithdrawGroupQuotaProposal::class)->handle($stale, $this->actor))->toThrow(ValidationException::class, 'ya se decidió');

    expect($shared->fresh()->status)->toBe(AgreementStatus::Accepted)
        ->and(GroupQuota::query()->where('group_id', $this->group->id)->where('career_id', $shared->career_id)->exists())->toBeTrue();
});
