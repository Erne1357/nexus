<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\AgreementStatus;
use App\Modules\AcademicLoad\Models\InterdepartmentalAgreement;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Actions\Agreements\AcceptRoomLoan;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
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
