<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Actions\Proposals\AcceptProposalReview;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\AcademicLoad\Models\ProposalReview;
use App\Modules\CatalogValidation\Enums\Catalog;
use App\Modules\CatalogValidation\Models\CatalogReview;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Building;
use App\Modules\Facilities\Models\Room;
use App\Modules\Facilities\Models\SubjectRoomDefault;
use App\Modules\Scheduling\Actions\CreateGroupsFromProposal;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Models\Group;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Spatie\Activitylog\Models\Activity;

/*
| CreateGroupsFromProposal: los grupos nacen solos cuando el jefe acepta la parte de su departamento en una propuesta
| de carrera (reglas-de-negocio.md, "Grupos y horarios"; tarea 3.3). Uno por cada grupo pedido de sus materias, con la
| carrera de la propuesta como dueña, sin duplicarse si la Action se repite.
*/

beforeEach(function () {
    $this->user = User::factory()->create();
    $this->term = Term::factory()->create(['code' => '20271']);
    $this->campus = Campus::factory()->create(['settings' => [...CampusProfile::DEFAULTS, 'uses_rooms' => false]]);
    $this->career = Career::factory()->create();
    $this->plan = StudyPlan::factory()->create(['career_id' => $this->career->id]);
    $this->cb = Department::factory()->create(['code' => 'CB']);
    $this->sc = Department::factory()->create(['code' => 'SC']);
    $this->proposal = CareerProposal::factory()->submitted()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'career_id' => $this->career->id,
    ]);
    $this->part = ProposalReview::factory()->create(['career_proposal_id' => $this->proposal->id, 'department_id' => $this->cb->id, 'decided_by' => $this->user->id]);
});

function createGroupsSubject(Department $department, array $attributes = []): Subject
{
    return Subject::factory()->create(['department_id' => $department->id, ...$attributes]);
}

function createGroupsItem(Subject $subject, int $groups, int $semester = 3): ProposalItem
{
    return ProposalItem::factory()->create([
        'career_proposal_id' => test()->proposal->id,
        'subject_id' => $subject->id,
        'study_plan_id' => test()->plan->id,
        'semester' => $semester,
        'requested_groups' => $groups,
    ]);
}

function createGroupsFor(?ProposalReview $part = null, ?User $user = null): Collection
{
    return app(CreateGroupsFromProposal::class)->handle($part ?? test()->part, $user ?? test()->user);
}

function createGroupsDefaultRoom(Subject $subject, int $capacity, int $priority = 1): SubjectRoomDefault
{
    $building = Building::factory()->create(['campus_id' => test()->campus->id]);
    $room = Room::factory()->capacity($capacity)->create(['building_id' => $building->id, 'campus_id' => test()->campus->id]);

    return SubjectRoomDefault::factory()->create(['subject_id' => $subject->id, 'room_id' => $room->id, 'campus_id' => test()->campus->id, 'priority' => $priority]);
}

it('crea tantos grupos como se pidieron en cada materia del departamento, con la carrera, plan, semestre, periodo y campus de la propuesta', function () {
    $subject = createGroupsSubject($this->cb);
    $item = createGroupsItem($subject, 3);

    $groups = createGroupsFor();

    expect($groups)->toHaveCount(3)
        ->and(Group::query()->count())->toBe(3);

    $group = $groups->first();
    expect($group->owner_career_id)->toBe($this->career->id)
        ->and($group->study_plan_id)->toBe($this->plan->id)
        ->and($group->semester)->toBe(3)
        ->and($group->term_id)->toBe($this->term->id)
        ->and($group->campus_id)->toBe($this->campus->id)
        ->and($group->subject_id)->toBe($subject->id)
        ->and($group->department_id)->toBe($this->cb->id)
        ->and($group->status)->toBe(GroupStatus::Planned)
        ->and($group->proposal_item_id)->toBe($item->id)
        ->and($group->teacher_id)->toBeNull();
});

it('los grupos nacen con la modalidad de la propuesta (ampliación de la ronda 2)', function () {
    $this->proposal->update(['modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);
    createGroupsItem(createGroupsSubject($this->cb), 2);

    $groups = createGroupsFor();

    expect($groups->pluck('modality_id')->unique()->all())->toBe([$this->proposal->modality_id])
        ->and(Group::query()->with('modality')->get()->map(fn (Group $group): string => $group->modality->code)->unique()->all())->toBe(['EAD']);
});

it('solo crea los grupos de las materias del departamento que acepta', function () {
    createGroupsItem(createGroupsSubject($this->cb), 1);
    createGroupsItem(createGroupsSubject($this->sc), 2);

    $groups = createGroupsFor();

    expect($groups)->toHaveCount(1)
        ->and($groups->sole()->department_id)->toBe($this->cb->id);
});

it('no crea grupos de las filas con cero grupos pedidos', function () {
    createGroupsItem(createGroupsSubject($this->cb), 0);

    expect(createGroupsFor())->toHaveCount(0)
        ->and(Group::query()->count())->toBe(0);
});

it('cada grupo nace con una clave provisional consecutiva por materia', function () {
    $first = createGroupsSubject($this->cb);
    $second = createGroupsSubject($this->cb);
    createGroupsItem($first, 2);
    createGroupsItem($second, 1);

    createGroupsFor();

    expect(Group::query()->where('subject_id', $first->id)->orderBy('code')->pluck('code')->all())->toBe(['1', '2'])
        ->and(Group::query()->where('subject_id', $second->id)->pluck('code')->all())->toBe(['1']);
});

it('la clave provisional salta las claves que otros grupos de la materia y el periodo ya usan', function () {
    $subject = createGroupsSubject($this->cb);
    Group::factory()->create(['term_id' => $this->term->id, 'subject_id' => $subject->id, 'code' => '1']);
    Group::factory()->cancelled()->create(['term_id' => $this->term->id, 'subject_id' => $subject->id, 'code' => '2']);
    createGroupsItem($subject, 2);

    createGroupsFor();

    expect(Group::query()->where('proposal_item_id', '!=', null)->orderBy('code')->pluck('code')->all())->toBe(['2', '3']);
});

it('la capacidad es la del salón por defecto de menor prioridad, sin contar los borrados', function () {
    $subject = createGroupsSubject($this->cb);
    createGroupsDefaultRoom($subject, 20, priority: 1)->delete();
    createGroupsDefaultRoom($subject, 40, priority: 3);
    createGroupsDefaultRoom($subject, 28, priority: 2);
    createGroupsItem($subject, 1);

    expect(createGroupsFor()->sole()->capacity)->toBe(28);
});

it('sin salón por defecto en el campus, la capacidad es 35, o 25 si la materia lleva laboratorio', function () {
    $plain = createGroupsSubject($this->cb);
    $lab = createGroupsSubject($this->cb, ['needs_lab' => true]);
    createGroupsItem($plain, 1);
    createGroupsItem($lab, 1);

    $groups = createGroupsFor();

    expect($groups->firstWhere('subject_id', $plain->id)->capacity)->toBe(35)
        ->and($groups->firstWhere('subject_id', $lab->id)->capacity)->toBe(25);
});

it('el salón por defecto de otro campus no cuenta', function () {
    $subject = createGroupsSubject($this->cb);
    $otherCampus = Campus::factory()->create();
    $building = Building::factory()->create(['campus_id' => $otherCampus->id]);
    $room = Room::factory()->capacity(50)->create(['building_id' => $building->id, 'campus_id' => $otherCampus->id]);
    SubjectRoomDefault::factory()->create(['subject_id' => $subject->id, 'room_id' => $room->id, 'campus_id' => $otherCampus->id]);
    createGroupsItem($subject, 1);

    expect(createGroupsFor()->sole()->capacity)->toBe(35);
});

it('crea el cupo de la carrera dueña igual a la capacidad', function () {
    $subject = createGroupsSubject($this->cb);
    createGroupsDefaultRoom($subject, 30);
    createGroupsItem($subject, 2);

    foreach (createGroupsFor() as $group) {
        $quota = $group->quotas()->sole();
        expect($quota->career_id)->toBe($this->career->id)
            ->and($quota->max_seats)->toBe(30)
            ->and($quota->is_owner)->toBeTrue();
    }
});

it('es idempotente: repetirla no crea nada, y un grupo cancelado a propósito no se vuelve a crear', function () {
    $item = createGroupsItem(createGroupsSubject($this->cb), 2);
    $groups = createGroupsFor();

    expect(createGroupsFor())->toHaveCount(0);

    $groups->first()->update(['status' => GroupStatus::Cancelled]);

    expect(createGroupsFor())->toHaveCount(0)
        ->and(Group::query()->where('proposal_item_id', $item->id)->count())->toBe(2);
});

it('si faltan grupos de una fila los completa sin repetir los que ya existen', function () {
    $item = createGroupsItem(createGroupsSubject($this->cb), 3);
    Group::factory()->forProposalItem($item)->create(['code' => '1']);

    $groups = createGroupsFor();

    expect($groups)->toHaveCount(2)
        ->and(Group::query()->where('proposal_item_id', $item->id)->count())->toBe(3);
});

it('deja una entrada de bitácora por grupo, con quien aceptó como autor', function () {
    $subject = createGroupsSubject($this->cb, ['name' => 'Cálculo Diferencial']);
    createGroupsItem($subject, 2);

    $groups = createGroupsFor();

    $entries = Activity::query()->where('event', 'created')->get();
    expect($entries)->toHaveCount(2);

    $entry = $entries->firstWhere('subject_id', $groups->first()->id);
    expect($entry->description)->toBe('Creó el grupo '.$groups->first()->code.' de Cálculo Diferencial')
        ->and($entry->causer_id)->toBe($this->user->id)
        ->and($entry->attribute_changes['attributes'])->toMatchArray([
            'code' => $groups->first()->code,
            'subject_id' => $subject->id,
            'owner_career_id' => $this->career->id,
            'semester' => 3,
            'capacity' => 35,
        ]);
});

it('avisa con GroupsCreated una sola vez, después del commit, y solo si creó algo', function () {
    createGroupsItem(createGroupsSubject($this->cb), 2);
    Event::fake([GroupsCreated::class]);

    DB::transaction(function () {
        $groups = createGroupsFor();

        Event::assertNotDispatched(GroupsCreated::class);
        test()->ids = $groups->pluck('id')->sort()->values()->all();
    });

    Event::assertDispatchedTimes(GroupsCreated::class, 1);
    Event::assertDispatched(GroupsCreated::class, fn (GroupsCreated $event) => $event->termId === $this->term->id
        && $event->campusId === $this->campus->id
        && collect($event->groupIds)->sort()->values()->all() === $this->ids);

    createGroupsFor();
    Event::assertDispatchedTimes(GroupsCreated::class, 1);
});

it('sin quien aceptó, la bitácora queda sin autor', function () {
    createGroupsItem(createGroupsSubject($this->cb), 1);

    app(CreateGroupsFromProposal::class)->handle($this->part);

    expect(Activity::query()->where('event', 'created')->sole()->causer_id)->toBeNull();
});

it('al aceptar la parte con AcceptProposalReview nacen los grupos de su departamento', function () {
    $subject = createGroupsSubject($this->cb);
    createGroupsItem($subject, 2);
    createGroupsItem(createGroupsSubject($this->sc), 1);
    AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->cb->id]);
    foreach (Catalog::general() as $catalog) {
        CatalogReview::factory()->general($catalog)->validated()->create(['term_id' => $this->term->id, 'department_id' => $this->cb->id]);
    }

    app(AcceptProposalReview::class)->handle($this->part, $this->user);

    expect(Group::query()->count())->toBe(2)
        ->and(Group::query()->where('subject_id', $subject->id)->count())->toBe(2)
        ->and(Activity::query()->where('event', 'created')->where('causer_id', $this->user->id)->count())->toBe(2);
});
