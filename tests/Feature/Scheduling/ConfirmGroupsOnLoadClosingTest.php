<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Transitions\CloseLoad;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Events\LoadClosing;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Models\User;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use Spatie\Activitylog\Models\Activity;

/*
| P1 (confirmado el 10 de noviembre de 2026): al cerrar la carga, todos los grupos activos del departamento en el campus
| y el periodo pasan de planeados a confirmados, en la misma transacción del cierre (LoadClosing).
*/

beforeEach(function () {
    $this->load = AcademicLoad::factory()->create(['status' => LoadStatus::Closed, 'department_id' => departmentKey(10)]);
    $this->subject = Subject::factory()->create(['department_id' => departmentKey(10)]);
});

function confirmingGroup(array $attributes = []): Group
{
    return Group::factory()->create([
        'term_id' => test()->load->term_id,
        'campus_id' => loadCampus()->id,
        'subject_id' => test()->subject->id,
        ...$attributes,
    ]);
}

it('confirma los grupos planeados del departamento y deja bitácora en la carga', function () {
    $planned = [confirmingGroup(), confirmingGroup()];
    $cancelled = confirmingGroup(['status' => GroupStatus::Cancelled]);
    $other = Group::factory()->create(['term_id' => $this->load->term_id, 'campus_id' => loadCampus()->id]);

    $actor = User::factory()->admin()->create();
    LoadClosing::dispatch($this->load, $actor);

    expect(array_map(fn (Group $group): GroupStatus => $group->fresh()->status, $planned))->toBe([GroupStatus::Confirmed, GroupStatus::Confirmed])
        ->and($cancelled->fresh()->status)->toBe(GroupStatus::Cancelled)
        ->and($other->fresh()->status)->toBe(GroupStatus::Planned);

    $activity = Activity::query()->where('event', 'groups_confirmed')->sole();
    expect($activity->subject_id)->toBe($this->load->id)
        ->and($activity->causer_id)->toBe($actor->id)
        ->and($activity->properties['groups'])->toBe(2);
});

it('cerrar la carga confirma sus grupos', function () {
    $this->load->update(['status' => LoadStatus::Staffing]);
    $this->load->modality->update(['settings' => [...ModalityProfile::DEFAULTS, 'uses_time_blocks' => false, 'uses_rooms' => false]]);
    $group = confirmingGroup(['teacher_id' => null, 'close_justification' => 'Contratación en trámite']);
    CareerCampus::factory()->create(['career_id' => $group->owner_career_id, 'campus_id' => $group->campus_id]);

    app(CloseLoad::class)->handle($this->load, User::factory()->admin()->create());

    expect($group->fresh()->status)->toBe(GroupStatus::Confirmed);
});
