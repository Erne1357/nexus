<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\RoomBlock;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;

/*
| AcceptRoomLoan (tarea 3.7, HC-10): el dueño del salón acepta el préstamo y el grupo se revalida.
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->from = Department::factory()->create();
    $this->to = Department::factory()->create();
    $this->group = Group::factory()->create(['subject_id' => Subject::factory()->create(['department_id' => $this->from->id])->id]);
    $this->loan = InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id,
        'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id,
    ]);
});

it('acepta el préstamo y avisa que cambió el horario del grupo', function () {
    Event::fake([GroupScheduleChanged::class]);

    app(AcceptRoomLoan::class)->handle($this->loan, $this->actor);

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted)
        ->and($this->loan->fresh()->decided_by)->toBe($this->actor->id);
    Event::assertDispatched(GroupScheduleChanged::class, fn (GroupScheduleChanged $event): bool => $event->group->is($this->group));
});

it('no acepta un grupo compartido ni un préstamo ya decidido, y no avisa', function () {
    Event::fake([GroupScheduleChanged::class]);
    $shared = InterdepartmentalAgreement::factory()->sharedGroup()->create(['from_department_id' => $this->from->id, 'to_department_id' => $this->to->id]);
    $decided = InterdepartmentalAgreement::factory()->rejected()->create(['group_id' => $this->group->id, 'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id]);

    expect(fn () => app(AcceptRoomLoan::class)->handle($shared, $this->actor))->toThrow(ValidationException::class)
        ->and(fn () => app(AcceptRoomLoan::class)->handle($decided, $this->actor))->toThrow(ValidationException::class);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('HC-02: no acepta si el bloque se empalma con otro grupo en el salón, y no avisa', function () {
    Event::fake([GroupScheduleChanged::class]);
    $other = Group::factory()->create(['term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id]);
    GroupSession::factory()->for($other)->on(1, '10:00', '12:00')->inRoom($this->loan->room)->create();

    expect(fn () => app(AcceptRoomLoan::class)->handle($this->loan, $this->actor))->toThrow(ValidationException::class);

    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Proposed);
    Event::assertNotDispatched(GroupScheduleChanged::class);
});

it('HC-11: no acepta si el salón tiene un bloqueo vigente en esas horas; uno de otro día no estorba', function () {
    RoomBlock::factory()->create(['room_id' => $this->loan->room_id, 'term_id' => null, 'day' => 2, 'starts_at' => '09:00', 'ends_at' => '11:00']);
    app(AcceptRoomLoan::class)->handle($this->loan, $this->actor);
    expect($this->loan->fresh()->status)->toBe(AgreementStatus::Accepted);

    $blocked = InterdepartmentalAgreement::factory()->create([
        'term_id' => $this->group->term_id, 'campus_id' => $this->group->campus_id, 'group_id' => $this->group->id, 'room_id' => $this->loan->room_id,
        'day' => 2, 'starts_at' => '10:00', 'ends_at' => '12:00', 'from_department_id' => $this->from->id, 'to_department_id' => $this->to->id,
    ]);

    expect(fn () => app(AcceptRoomLoan::class)->handle($blocked, $this->actor))->toThrow(ValidationException::class);
});
