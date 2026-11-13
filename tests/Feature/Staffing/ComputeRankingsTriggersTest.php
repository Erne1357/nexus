<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\AccessSync;
use App\Modules\Faculty\Events\AdscriptionChanged;
use App\Modules\Faculty\Events\AvailabilityChanged;
use App\Modules\Faculty\Events\ExternalLoadChanged;
use App\Modules\Faculty\Events\PositionChanged;
use App\Modules\Faculty\Events\QualificationChanged;
use App\Modules\Faculty\Events\TeacherAccessChanged;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Events\GroupCancelled;
use App\Modules\Scheduling\Events\GroupScheduleChanged;
use App\Modules\Scheduling\Events\GroupsCreated;
use App\Modules\Scheduling\Events\GroupTeacherChanged;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Actions\RankGroups;
use App\Modules\Staffing\Jobs\ComputeRankings;
use App\Modules\Staffing\Listeners\RerankOnFacultyChange;
use App\Modules\Staffing\Listeners\RerankOnGroupChange;
use App\Modules\Staffing\Listeners\RerankOnGroupsCreated;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;

/*
| Cuándo se recalcula el ranking docente (tarea 3.8): Staffing escucha los eventos de Scheduling y de Faculty y
| encola ComputeRankings por periodo, campus y departamento, con un retraso corto y uno solo por alcance en la cola.
| Sin periodo en el evento, cuentan los periodos con alguna carga en `proposed`, `staffing` o `adjusting`.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->closedTerm = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->campus = Campus::factory()->create();
    $this->otherCampus = Campus::factory()->create();
    $this->department = Department::factory()->create();
    $this->otherDepartment = Department::factory()->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->department->id]);
    $this->otherSubject = Subject::factory()->create(['department_id' => $this->otherDepartment->id]);
    AcademicLoad::factory()->inStatus(LoadStatus::Staffing)->create([
        'term_id' => $this->term->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->department->id,
    ]);
    AcademicLoad::factory()->inStatus(LoadStatus::Published)->create([
        'term_id' => $this->closedTerm->id, 'modality_id' => $this->campus->modality_id, 'department_id' => $this->department->id,
    ]);
    $this->group = fn (Term $term, Campus $campus, Subject $subject, array $attributes = []): Group => Group::factory()->create($attributes + [
        'term_id' => $term->id, 'campus_id' => $campus->id, 'subject_id' => $subject->id,
    ]);
    $this->ownGroup = ($this->group)($this->term, $this->campus, $this->subject);
    ($this->group)($this->term, $this->otherCampus, $this->otherSubject);
    ($this->group)($this->term, $this->otherCampus, $this->otherSubject, ['status' => 'cancelled']);
    ($this->group)($this->closedTerm, $this->campus, $this->subject);

    // Adscrito al departamento y preferente en una materia de otro, que solo se ofrece en el otro campus.
    $this->teacher = Teacher::factory()->create();
    TeacherDepartment::factory()->create(['teacher_id' => $this->teacher->id, 'department_id' => $this->department->id]);
    TeacherSubjectQualification::factory()->for($this->teacher)->for($this->otherSubject)->preferente()->create();
    $this->teacherScopes = [
        [$this->term->id, $this->campus->id, $this->department->id],
        [$this->term->id, $this->otherCampus->id, $this->otherDepartment->id],
    ];

    Queue::fake();
});

/**
 * @param  list<array{0: string, 1: string, 2: string}>  $scopes
 * @return list<array{0: string, 1: string, 2: string}>
 */
function sortedRankingScopes(array $scopes): array
{
    sort($scopes);

    return $scopes;
}

/**
 * @return list<array{0: string, 1: string, 2: string}>
 */
function queuedRankingScopes(): array
{
    return sortedRankingScopes(Queue::pushed(ComputeRankings::class)
        ->map(fn (ComputeRankings $job): array => [$job->termId, $job->campusId, $job->departmentId])
        ->all());
}

it('registra sus listeners en StaffingServiceProvider', function () {
    Event::fake();

    Event::assertListening(GroupsCreated::class, RerankOnGroupsCreated::class);
    Event::assertListening(GroupScheduleChanged::class, RerankOnGroupChange::class);
    Event::assertListening(GroupCancelled::class, RerankOnGroupChange::class);
    Event::assertListening(GroupTeacherChanged::class, RerankOnGroupChange::class);

    foreach ([PositionChanged::class, ExternalLoadChanged::class, AvailabilityChanged::class, QualificationChanged::class,
        AdscriptionChanged::class, TeacherUpdated::class, TeacherAccessChanged::class] as $event) {
        Event::assertListening($event, RerankOnFacultyChange::class);
    }
});

it('Ranking docente: al nacer grupos encola uno por cada departamento de esos grupos', function () {
    $sibling = ($this->group)($this->term, $this->campus, $this->subject);
    $foreign = ($this->group)($this->term, $this->campus, $this->otherSubject);

    GroupsCreated::dispatch($this->term->id, $this->ownGroup->modality_id, [$this->ownGroup->id, $sibling->id, $foreign->id]);

    expect(queuedRankingScopes())->toBe(sortedRankingScopes([
        [$this->term->id, $this->campus->id, $this->department->id],
        [$this->term->id, $this->campus->id, $this->otherDepartment->id],
    ]));
});

it('Ranking docente: un horario cambiado o un grupo cancelado encola su alcance y los de su docente', function (string $event) {
    $this->ownGroup->update(['teacher_id' => $this->teacher->id]);

    $event::dispatch($this->ownGroup);

    expect(queuedRankingScopes())->toBe(sortedRankingScopes($this->teacherScopes));
})->with([GroupScheduleChanged::class, GroupCancelled::class]);

it('Ranking docente: un grupo sin docente solo encola su propio alcance', function () {
    $foreign = ($this->group)($this->term, $this->campus, $this->otherSubject);

    GroupScheduleChanged::dispatch($foreign);

    expect(queuedRankingScopes())->toBe([[$this->term->id, $this->campus->id, $this->otherDepartment->id]]);
});

it('Ranking docente: un cambio de docente encola el alcance del grupo y los del docente anterior y el nuevo', function () {
    $foreign = ($this->group)($this->term, $this->campus, $this->otherSubject);
    $previous = Teacher::factory()->create();
    $thirdDepartment = Department::factory()->create();
    TeacherDepartment::factory()->create(['teacher_id' => $previous->id, 'department_id' => $thirdDepartment->id]);
    ($this->group)($this->term, $this->campus, Subject::factory()->create(['department_id' => $thirdDepartment->id]));

    GroupTeacherChanged::dispatch($foreign, $previous->id, $this->teacher->id);

    expect(queuedRankingScopes())->toBe(sortedRankingScopes([
        ...$this->teacherScopes,
        [$this->term->id, $this->campus->id, $this->otherDepartment->id],
        [$this->term->id, $this->campus->id, $thirdDepartment->id],
    ]));
});

it('Ranking docente: un cambio de Faculty encola los alcances del docente en los periodos con carga abierta', function (string $event, array $arguments) {
    $event::dispatch(...$arguments);

    expect(queuedRankingScopes())->toBe(sortedRankingScopes($this->teacherScopes));
})->with([
    'plaza' => [PositionChanged::class, fn () => [TeacherPosition::factory()->for($this->teacher)->create()]],
    'perfil' => [QualificationChanged::class, fn () => [TeacherSubjectQualification::factory()->for($this->teacher)->apto()->create()]],
    'adscripción' => [AdscriptionChanged::class, fn () => [TeacherDepartment::factory()->create(['teacher_id' => $this->teacher->id, 'is_primary' => false])]],
    'datos del docente' => [TeacherUpdated::class, fn () => [$this->teacher, ['name']]],
    'acceso' => [TeacherAccessChanged::class, fn () => [$this->teacher, AccessSync::Updated]],
]);

it('Ranking docente: horas externas y disponibilidad encolan solo el periodo del cambio', function () {
    ExternalLoadChanged::dispatch(TeacherExternalLoad::factory()->for($this->teacher)->create(['term_id' => $this->closedTerm->id]));
    AvailabilityChanged::dispatch($this->teacher, $this->closedTerm);

    expect(queuedRankingScopes())->toBe([[$this->closedTerm->id, $this->campus->id, $this->department->id]]);
});

it('Ranking docente: quien perdió la adscripción se recalcula donde todavía tiene ranking', function () {
    $former = Teacher::factory()->create();
    $adscription = TeacherDepartment::factory()->create(['teacher_id' => $former->id, 'department_id' => $this->department->id]);
    StaffingRanking::factory()->create(['group_id' => $this->ownGroup->id, 'teacher_id' => $former->id]);
    $adscription->delete();

    AdscriptionChanged::dispatch($adscription);

    expect(queuedRankingScopes())->toBe([[$this->term->id, $this->campus->id, $this->department->id]]);
});

it('Ranking docente: sin carga abierta en ningún periodo, un cambio de Faculty no encola nada', function () {
    AcademicLoad::query()->update(['status' => LoadStatus::Closed->value]);

    TeacherUpdated::dispatch($this->teacher, ['name']);

    Queue::assertNothingPushed();
});

it('Ranking docente: espera uno solo por alcance en la cola, con 5 segundos de retraso', function () {
    ComputeRankings::dispatch($this->term->id, $this->campus->id, $this->department->id);
    GroupScheduleChanged::dispatch($this->ownGroup);
    ComputeRankings::dispatch($this->term->id, $this->otherCampus->id, $this->department->id);

    Queue::assertPushed(ComputeRankings::class, 2);
    Queue::assertPushed(ComputeRankings::class, fn (ComputeRankings $job): bool => $job->uniqueId() === "{$this->term->id}:{$this->campus->id}:{$this->department->id}");

    GroupScheduleChanged::dispatch(($this->group)($this->term, $this->campus, $this->otherSubject));

    Queue::assertPushed(ComputeRankings::class, fn (ComputeRankings $job): bool => $job->departmentId === $this->otherDepartment->id && $job->delay === 5);
});

it('el job corre RankGroups sin encimar dos corridas del mismo alcance', function () {
    TeacherDepartment::factory()->create(['department_id' => $this->department->id]);
    $job = new ComputeRankings($this->term->id, $this->campus->id, $this->department->id);
    $middleware = $job->middleware();

    $job->handle(app(RankGroups::class));

    expect(StaffingRanking::query()->where('group_id', $this->ownGroup->id)->count())->toBe(2)
        ->and($job)
        ->uniqueFor->toBe(3600)
        ->maxExceptions->toBe(1)
        ->and($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class)
        ->and($middleware[0]->key)->toBe($job->uniqueId());
});

it('el job no hace nada si el departamento ya no existe', function () {
    $job = new ComputeRankings($this->term->id, $this->campus->id, $this->department->id);
    $this->department->delete();

    $job->handle(app(RankGroups::class));

    expect(StaffingRanking::query()->count())->toBe(0);
});

it('Ranking docente: un cambio solo de salón no vuelve a rankear, porque el salón no cambia el ranking', function () {
    $this->ownGroup->update(['teacher_id' => $this->teacher->id]);

    GroupScheduleChanged::dispatch($this->ownGroup, false);

    expect(queuedRankingScopes())->toBe([]);
});
