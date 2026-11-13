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
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RankGroups;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Models\ChangeRequest;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\ChangeRequestsQuery;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| Un grupo cambia de campus (M8, MS9) dentro de la carga de su modalidad (M1). Staffing ya no guarda copias del campus
| del grupo: rankings, asignaciones y solicitudes lo leen del grupo, así que nada queda con el campus de antes. El
| listener síncrono de GroupMovedToCampus solo encola, después del commit, el ranking del departamento en su modalidad
| (un solo alcance: los dos campus están en él). RankGroups borra las filas viejas de un alcance por sus grupos.
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

it('M8: tras cambiar el grupo de campus, el ranking de su departamento y modalidad lo rankea en el campus nuevo', function () {
    StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id]);

    moveToC1();
    app(RankGroups::class)->handle($this->term, $this->pres, $this->department);

    expect(StaffingRanking::query()->where('group_id', $this->group->id)->where('teacher_id', $this->teacher->id)->sole()->group->campus_id)->toBe($this->c1->id);
});

it('RankGroups borra las filas viejas de un alcance por sus grupos, aunque la copia de la modalidad no coincida', function () {
    $ead = modalityOf('EAD');
    StaffingRanking::factory()->create(['group_id' => $this->group->id, 'teacher_id' => $this->teacher->id]);
    DB::table('groups')->where('id', $this->group->id)->update(['modality_id' => $ead->id]);

    app(RankGroups::class)->handle($this->term, $ead, $this->department);

    expect(StaffingRanking::query()->where('group_id', $this->group->id)->pluck('modality_id')->all())->toBe([$ead->id]);
});

it('M8, MS6: la solicitud del grupo se lista en el campus nuevo, y la aplica el coordinador del campus nuevo, no el del de antes', function () {
    $request = ChangeRequest::factory()->forGroup($this->group)->schedule()->approved()->create();
    $c2Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c2->id, 'career_id' => $this->career->id])->create();
    $c1Only = User::factory()->withRole(Role::Coordinador, ['modality_id' => $this->pres->id, 'campus_id' => $this->c1->id, 'career_id' => $this->career->id])->create();

    expect($c2Only->can('apply', $request))->toBeTrue();

    moveToC1();
    $admin = app(Actor::class)->forUser(User::factory()->admin()->create());
    $list = fn (Campus $campus) => app(ChangeRequestsQuery::class)->listFor($admin, $this->term->id, $this->pres->id, $campus->id, $this->department->id)->modelKeys();
    $request = $request->fresh();

    expect($list($this->c1))->toBe([$request->id])
        ->and($list($this->c2))->toBe([])
        ->and($c2Only->fresh()->can('apply', $request))->toBeFalse()
        ->and($c1Only->can('apply', $request))->toBeTrue();
});

it('M1, M8, MS9: después del commit recalcula una sola vez el ranking del departamento en su modalidad, que tiene los dos campus', function () {
    Queue::fake([ComputeRankings::class]);
    // GroupScheduleChanged, que sale después del commit, también rankea (RerankOnGroupChange): aquí solo se ve el listener.
    Event::fake([GroupScheduleChanged::class]);

    DB::transaction(function (): void {
        moveToC1();

        Queue::assertNotPushed(ComputeRankings::class);
    });

    $scopes = Queue::pushed(ComputeRankings::class)->map(fn (ComputeRankings $job): string => "{$job->termId}|{$job->modalityId}|{$job->departmentId}")->all();

    expect($scopes)->toBe(["{$this->term->id}|{$this->pres->id}|{$this->department->id}"]);
});
