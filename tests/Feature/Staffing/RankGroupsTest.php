<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Validation\ScheduleValidator;
use App\Modules\Staffing\Actions\RankGroups;
use App\Modules\Staffing\Events\RankingsComputed;
use App\Modules\Staffing\Models\StaffingRanking;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;

/*
| RankGroups: el ranking docente de los grupos de un departamento en un periodo y campus (reglas-de-negocio.md,
| «Ranking docente (desempate)»). Reescribe `staffing_rankings` en una transacción y avisa con RankingsComputed.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->campus = Campus::factory()->create();
    $this->department = Department::factory()->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->department->id]);
    $this->group = fn (array $attributes = []): Group => Group::factory()->create($attributes + [
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => $this->subject->id,
    ]);
    $this->teacher = function (string $name, int $baseHours = 0): Teacher {
        $teacher = Teacher::factory()->create(['name' => $name]);
        TeacherDepartment::factory()->create(['teacher_id' => $teacher->id, 'department_id' => $this->department->id]);

        if ($baseHours > 0) {
            TeacherPosition::factory()->for($teacher)->asignatura($baseHours)->create();
        }

        return $teacher;
    };
    $this->rank = fn (): int => app(RankGroups::class)->handle($this->term, $this->campus, $this->department);
    $this->ranking = fn (Group $group): array => StaffingRanking::query()->where('group_id', $group->id)->inRankOrder()
        ->with('teacher')->get()->map(fn (StaffingRanking $row): string => $row->teacher->name)->all();
});

it('Ranking docente: ordena a los candidatos de cada grupo del departamento como dice el RankingScorer', function () {
    $first = ($this->group)();
    $second = ($this->group)();
    ($this->teacher)('Beatriz', 10);
    $carlos = ($this->teacher)('Carlos', 10);
    TeacherSubjectQualification::factory()->for($carlos)->for($this->subject)->preferente()->create();
    ($this->teacher)('Ana', 20);

    $ranked = ($this->rank)();

    $row = StaffingRanking::query()->where('group_id', $first->id)->where('teacher_id', $carlos->id)->sole();

    expect($ranked)->toBe(2)
        ->and(($this->ranking)($first))->toBe(['Ana', 'Carlos', 'Beatriz'])
        ->and(($this->ranking)($second))->toBe(['Ana', 'Carlos', 'Beatriz'])
        ->and($row->rank)->toBe(2)
        ->and((float) $row->score)->toBe(3.0)
        ->and($row->term_id)->toBe($this->term->id)
        ->and($row->campus_id)->toBe($this->campus->id)
        ->and($row->computed_at)->not->toBeNull()
        ->and(collect($row->breakdown)->sortKeys()->all())->toBe([
            'base_hours' => 10,
            'evaluation' => null,
            'flags' => ['sin_evaluacion'],
            'from_other_department' => false,
            'history_count' => 0,
            'qualification' => 'preferente',
            'remaining_after_hours' => 5,
            'remaining_hours' => 10,
        ]);
});

it('cada fila del ranking copia la modalidad de su grupo (ampliación de la ronda 2)', function () {
    $group = ($this->group)(['modality_id' => Modality::query()->where('code', 'EAD')->value('id')]);
    ($this->teacher)('Ana', 10);
    ($this->teacher)('Beatriz', 10);

    ($this->rank)();

    expect(StaffingRanking::query()->where('group_id', $group->id)->count())->toBe(2)
        ->and(StaffingRanking::query()->pluck('modality_id')->unique()->all())->toBe([$group->modality_id]);
});

it('HC-08: un candidato sin horas para el grupo queda marcado «sin horas» y baja al bloque final', function () {
    $group = ($this->group)();
    ($this->teacher)('Ana', 4);
    ($this->teacher)('Beatriz', 6);

    ($this->rank)();

    $ana = StaffingRanking::query()->where('group_id', $group->id)->whereHas('teacher', fn ($query) => $query->where('name', 'Ana'))->sole();

    expect(($this->ranking)($group))->toBe(['Beatriz', 'Ana'])
        ->and($ana->breakdown['flags'])->toContain('sin_horas')
        ->and($ana->breakdown['remaining_hours'])->toBe(4)
        ->and($ana->breakdown['remaining_after_hours'])->toBe(-1);
});

it('Ranking docente: marca a un preferente de otro departamento', function () {
    $group = ($this->group)();
    $other = Teacher::factory()->create(['name' => 'Otra']);
    TeacherDepartment::factory()->create(['teacher_id' => $other->id]);
    TeacherSubjectQualification::factory()->for($other)->for($this->subject)->preferente()->create();

    ($this->rank)();

    expect(StaffingRanking::query()->where('group_id', $group->id)->sole()->breakdown['from_other_department'])->toBeTrue();
});

it('HC-01: un candidato que ya da clase a esa hora en otro grupo queda marcado «choque» y baja al bloque final', function () {
    $group = ($this->group)();
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    $busy = ($this->teacher)('Ana', 40);
    ($this->teacher)('Beatriz', 10);
    $elsewhere = Group::factory()->withTeacher($busy)->create(['term_id' => $this->term->id, 'campus_id' => Campus::factory()->create()->id]);
    GroupSession::factory()->for($elsewhere)->on(1, '08:00', '10:00')->create();

    ($this->rank)();

    $row = StaffingRanking::query()->where('group_id', $group->id)->where('teacher_id', $busy->id)->sole();

    expect(($this->ranking)($group))->toBe(['Beatriz', 'Ana'])
        ->and($row->breakdown['flags'])->toContain('choque');
});

it('SC-09: un candidato que quedaría con menos del traslado mínimo entre campus se marca «traslado justo» sin bajar de lugar', function () {
    $group = ($this->group)();
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    $ana = ($this->teacher)('Ana', 20);
    ($this->teacher)('Beatriz', 10);
    $elsewhere = Group::factory()->withTeacher($ana)->create(['term_id' => $this->term->id, 'campus_id' => Campus::factory()->create()->id]);
    GroupSession::factory()->for($elsewhere)->on(1, '09:30', '10:30')->create();

    ($this->rank)();

    $flags = StaffingRanking::query()->where('group_id', $group->id)->where('teacher_id', $ana->id)->sole()->breakdown['flags'];

    expect(($this->ranking)($group))->toBe(['Ana', 'Beatriz'])
        ->and($flags)->toContain('traslado_justo')
        ->and($flags)->not->toContain('choque');
});

it('HC-01: lee el horario de ahora aunque el validador (scoped) ya tenga una foto de antes en la misma petición', function () {
    $group = ($this->group)();
    GroupSession::factory()->for($group)->on(1, '07:00', '09:00')->create();
    $busy = ($this->teacher)('Ana', 40);
    ($this->teacher)('Beatriz', 10);
    app(ScheduleValidator::class)->stateFor($this->term->id, $group->modality_id);
    $elsewhere = Group::factory()->withTeacher($busy)->create(['term_id' => $this->term->id, 'campus_id' => Campus::factory()->create()->id]);
    GroupSession::factory()->for($elsewhere)->on(1, '08:00', '10:00')->create();

    ($this->rank)();

    expect(StaffingRanking::query()->where('group_id', $group->id)->where('teacher_id', $busy->id)->sole()->breakdown['flags'])->toContain('choque');
});

it('Ranking docente: volver a calcular reemplaza las filas y saca a quien ya no está adscrito', function () {
    $group = ($this->group)();
    ($this->teacher)('Ana', 20);
    $beatriz = ($this->teacher)('Beatriz', 10);
    ($this->teacher)('Carlos', 5);

    ($this->rank)();
    ($this->rank)();

    expect(StaffingRanking::query()->where('group_id', $group->id)->count())->toBe(3);

    $beatriz->adscriptions()->delete();
    ($this->rank)();

    expect(StaffingRanking::query()->where('group_id', $group->id)->inRankOrder()->pluck('rank')->all())->toBe([1, 2])
        ->and(($this->ranking)($group))->toBe(['Ana', 'Carlos']);
});

it('Ranking docente: los grupos cancelados no tienen filas y pierden las que tenían', function () {
    ($this->group)();
    $cancelled = ($this->group)(['status' => 'cancelled']);
    $teacher = ($this->teacher)('Ana', 20);
    StaffingRanking::factory()->create(['group_id' => $cancelled->id, 'teacher_id' => $teacher->id]);

    expect(($this->rank)())->toBe(1)
        ->and(StaffingRanking::query()->where('group_id', $cancelled->id)->exists())->toBeFalse();
});

it('Ranking docente: no toca el ranking de otro departamento ni de otro campus', function () {
    ($this->group)();
    ($this->teacher)('Ana', 20);
    $otherDepartment = StaffingRanking::factory()->create();
    $otherCampus = StaffingRanking::factory()->create([
        'group_id' => ($this->group)(['campus_id' => Campus::factory()->create()->id])->id,
    ]);

    ($this->rank)();

    expect(StaffingRanking::query()->whereKey([$otherDepartment->id, $otherCampus->id])->count())->toBe(2);
});

it('Ranking docente: avisa con RankingsComputed el periodo, el campus y el departamento', function () {
    Event::fake([RankingsComputed::class]);
    ($this->group)();

    ($this->rank)();

    Event::assertDispatched(RankingsComputed::class, fn (RankingsComputed $event): bool => $event->termId === $this->term->id
        && $event->campusId === $this->campus->id
        && $event->departmentId === $this->department->id);
});

it('Ranking docente: los pesos de staffing.weights cambian el orden', function () {
    $group = ($this->group)();
    $veteran = ($this->teacher)('Ana', 10);
    $preferred = ($this->teacher)('Beatriz', 10);
    TeacherSubjectQualification::factory()->for($preferred)->for($this->subject)->preferente()->create();
    foreach (['20263', '20261'] as $code) {
        TeacherSubjectHistory::factory()->create(['teacher_id' => $veteran->id, 'subject_id' => $this->subject->id, 'term_code' => $code]);
    }

    ($this->rank)();
    $byDefault = ($this->ranking)($group);

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'staffing.weights', 'value' => ['per_group_taught' => 5]]);
    ($this->rank)();

    expect($byDefault)->toBe(['Beatriz', 'Ana'])
        ->and(($this->ranking)($group))->toBe(['Ana', 'Beatriz']);
});

it('Ranking docente: un departamento de 40 grupos y 30 candidatos se rankea con un número acotado de consultas', function () {
    $career = Career::factory()->create();
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $subjects = Subject::factory()->count(4)->create(['department_id' => $this->department->id]);
    $teachers = collect(range(1, 30))->map(fn (int $i): Teacher => ($this->teacher)("Docente {$i}", 1 + $i % 20));
    $outsider = Teacher::factory()->create();
    TeacherSubjectQualification::factory()->for($outsider)->for($subjects[3])->preferente()->create();

    foreach (range(0, 39) as $i) {
        $group = ($this->group)([
            'subject_id' => $subjects[$i % 4]->id,
            'owner_career_id' => $career->id,
            'study_plan_id' => $plan->id,
            'teacher_id' => $i < 20 ? $teachers[$i]->id : null,
        ]);
        GroupSession::factory()->for($group)->on(1 + $i % 5, sprintf('%02d:00', 7 + intdiv($i, 5)), sprintf('%02d:00', 8 + intdiv($i, 5)))->create();
    }

    DB::flushQueryLog();
    DB::enableQueryLog();
    $ranked = ($this->rank)();
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    // Hoy son 38: una consulta por grupo o por docente pasaría de 80.
    expect($ranked)->toBe(40)
        ->and(StaffingRanking::query()->count())->toBe(40 * 30 + 10)
        ->and($queries)->toBeLessThanOrEqual(60);
});
