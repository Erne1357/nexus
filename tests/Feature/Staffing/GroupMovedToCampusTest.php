<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Scheduling\Actions\MoveGroupToCampus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RankGroups;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\ChangeRequestsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;

/*
| Un grupo cambia de campus (M8, MS9): Staffing guarda copias del campus del grupo en `staffing_rankings` y
| `change_requests`. Siguen al grupo en la misma transacción (GroupMovedToCampus, síncrono) y se recalcula el ranking
| del departamento en el campus de antes y en el nuevo. RankGroups borra las filas viejas de un alcance por sus grupos,
| no por la copia del campus.
*/

beforeEach(function () {
    $this->pres = modalityOf('PRES');
    $this->c1 = Campus::factory()->create(['code' => 'C1', 'name' => 'Campus I']);
    $this->c2 = Campus::factory()->create(['code' => 'C2', 'name' => 'Campus II']);
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->department = Department::factory()->create();
    $this->career = Career::factory()->create(['code' => 'ISC', 'department_id' => $this->department->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c1->id]);
    CareerCampus::factory()->create(['career_id' => $this->career->id, 'campus_id' => $this->c2->id]);
    AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create(['term_id' => $this->term->id, 'modality_id' => $this->pres->id, 'department_id' => $this->department->id]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->c2->id,
        'subject_id' => Subject::factory()->create(['department_id' => $this->department->id])->id,
        'owner_career_id' => $this->career->id,
        'study_plan_id' => StudyPlan::factory()->create(['career_id' => $this->career->id])->id,
    ]);
    $this->teacher = Teacher::factory()->create(['name' => 'Ana']);
    TeacherDepartment::factory()->create(['teacher_id' => $this->teacher->id, 'department_id' => $this->department->id]);
    TeacherPosition::factory()->for($this->teacher)->asignatura(20)->create();
    $this->actingAs(User::factory()->admin()->create());
});

function moveToC1(): Group
{
    return app(MoveGroupToCampus::class)->handle(test()->group, test()->c1);
}

it('M8: tras cambiar el grupo de campus, rankear el departamento en el campus nuevo funciona y no queda ninguna fila con el campus de antes', function () {
    StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id]);

    moveToC1();
    app(RankGroups::class)->handle($this->term, $this->c1, $this->department);

    expect(StaffingRanking::query()->where('group_id', $this->group->id)->where('campus_id', $this->c2->id)->exists())->toBeFalse()
        ->and(StaffingRanking::query()->where('group_id', $this->group->id)->where('teacher_id', $this->teacher->id)->sole()->campus_id)->toBe($this->c1->id);
});

it('RankGroups borra las filas viejas de un alcance por sus grupos, aunque la copia del campus no coincida', function () {
    StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id]);
    DB::table('groups')->where('id', $this->group->id)->update(['campus_id' => $this->c1->id]);

    app(RankGroups::class)->handle($this->term, $this->c1, $this->department);

    expect(StaffingRanking::query()->where('group_id', $this->group->id)->pluck('campus_id')->all())->toBe([$this->c1->id]);
});

it('M8, MS6: la solicitud pendiente del grupo se lista en el campus nuevo, y la aplica el coordinador del campus nuevo, no el del de antes', function () {
    $request = ChangeRequest::factory()->forGroup($this->group)->schedule()->approved()->create();
    $c2Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $c1Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create();

    expect($c2Only->can('apply', $request))->toBeTrue();

    moveToC1();
    $admin = app(Actor::class)->forUser(User::factory()->admin()->create());
    $list = fn (Campus $campus) => app(ChangeRequestsQuery::class)->listFor($admin, $this->term->id, $campus->id, $this->department->id)->modelKeys();
    $request->refresh();

    expect($request->campus_id)->toBe($this->c1->id)
        ->and($list($this->c1))->toBe([$request->id])
        ->and($list($this->c2))->toBe([])
        ->and($c2Only->fresh()->can('apply', $request))->toBeFalse()
        ->and($c1Only->can('apply', $request))->toBeTrue();
});

it('M8: Staffing sigue al grupo dentro de la transacción del cambio, y después del commit recalcula el ranking en los dos campus', function () {
    Queue::fake([ComputeRankings::class]);
    $ranking = StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id]);
    $request = ChangeRequest::factory()->forGroup($this->group)->pending()->create();

    DB::transaction(function () use ($ranking, $request): void {
        moveToC1();

        expect($ranking->fresh()->campus_id)->toBe($this->c1->id)
            ->and($request->fresh()->campus_id)->toBe($this->c1->id);
        Queue::assertNotPushed(ComputeRankings::class);
    });

    $scopes = Queue::pushed(ComputeRankings::class)->map(fn (ComputeRankings $job): string => "{$job->campusId}|{$job->departmentId}")->unique()->values()->all();

    expect($scopes)->toContain("{$this->c2->id}|{$this->department->id}", "{$this->c1->id}|{$this->department->id}");
});
