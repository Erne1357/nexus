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
use App\Modules\Scheduling\Actions\CancelGroup;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Spatie\Activitylog\Models\Activity;

/*
| CancelGroup (re-análisis, tarea 8.3a; HC-15: un grupo cancelado no se borra): el grupo queda cancelado, sus sesiones dejan
| de contar para los choques y sus acuerdos vivos (préstamos de salón y cupos compartidos) se cancelan. Solo con la carga
| editable (LoadEditability).
*/

beforeEach(function () {
    $this->actor = User::factory()->create();
    $this->department = Department::factory()->create(['code' => 'CB']);
    $this->group = Group::factory()->withQuota(30)->create([
        'code' => 'E',
        'subject_id' => Subject::factory()->create(['department_id' => $this->department->id])->id,
    ]);
    $room = Room::factory()->capacity(40)->create(['building_id' => Building::factory()->create(['campus_id' => $this->group->campus_id])->id]);
    GroupSession::factory()->for($this->group)->inRoom($room)->on(1, '07:00', '09:00')->create();
    $this->load = AcademicLoad::factory()->inStatus(LoadStatus::Reanalysis)->create(['term_id' => $this->group->term_id, 'modality_id' => $this->group->modality_id, 'department_id' => $this->department->id]);
});

function cancelGroupErrors(Group $group, User $actor): array
{
    try {
        app(CancelGroup::class)->handle($group, $actor);
    } catch (ValidationException $e) {
        return $e->errors();
    }

    return [];
}

it('cancela el grupo, sus sesiones dejan de contar y deja bitácora y aviso después del commit', function () {
    Event::fake([GroupCancelled::class]);

    app(CancelGroup::class)->handle($this->group, $this->actor);

    $activity = Activity::query()->where('event', 'cancelled')->sole();

    expect($this->group->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and(GroupSession::query()->where('group_id', $this->group->id)->value('is_active'))->toBeFalse()
        ->and($activity->causer_id)->toBe($this->actor->id)
        ->and($activity->subject_id)->toBe($this->group->id)
        ->and($activity->attribute_changes['old']['status'])->toBe('planned')
        ->and($activity->attribute_changes['attributes']['status'])->toBe('cancelled');
    Event::assertDispatched(GroupCancelled::class, fn (GroupCancelled $event): bool => $event->group->is($this->group));
});

it('cancela los acuerdos vivos del grupo y deja los demás como estaban', function () {
    $loan = InterdepartmentalAgreement::factory()->create(['group_id' => $this->group->id, 'term_id' => $this->group->term_id]);
    $shared = InterdepartmentalAgreement::factory()->sharedGroup(5)->accepted()->create(['group_id' => $this->group->id, 'term_id' => $this->group->term_id]);
    $rejected = InterdepartmentalAgreement::factory()->rejected()->create(['group_id' => $this->group->id, 'term_id' => $this->group->term_id]);

    app(CancelGroup::class)->handle($this->group, $this->actor);

    expect($loan->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($shared->fresh()->status)->toBe(AgreementStatus::Cancelled)
        ->and($rejected->fresh()->status)->toBe(AgreementStatus::Rejected);
});

it('no cancela con la carga fuera de edición ni un grupo ya cancelado', function () {
    $this->load->update(['status' => LoadStatus::Published]);

    expect(cancelGroupErrors($this->group, $this->actor))->toHaveKey('group')
        ->and($this->group->fresh()->status)->toBe(GroupStatus::Planned);

    $this->load->update(['status' => LoadStatus::Reanalysis]);
    $this->group->update(['status' => GroupStatus::Cancelled]);

    expect(cancelGroupErrors($this->group, $this->actor)['group'][0])->toBe('El grupo E está cancelado: no se editan grupos.')
        ->and(Activity::query()->where('event', 'cancelled')->count())->toBe(0);
});

it('si la transacción de quien llama se revierte, no avisa', function () {
    Event::fake([GroupCancelled::class]);

    try {
        DB::transaction(function (): void {
            app(CancelGroup::class)->handle($this->group, $this->actor);

            throw new RuntimeException('se revierte');
        });
    } catch (RuntimeException) {
    }

    expect($this->group->fresh()->status)->toBe(GroupStatus::Planned);
    Event::assertNotDispatched(GroupCancelled::class);
});
