<?php

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Enums\PositionKind;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Staffing\Models\StaffingRanking;
use App\Modules\Staffing\Queries\RankingInputsQuery;
use Illuminate\Support\Facades\DB;

/*
| Los insumos de Faculty para el ranking docente (RankingInputsQuery): candidatos del departamento y preferentes de
| otros, horas base, historial y evaluación dentro de su ventana y puesto del periodo anterior. Lotes fijos.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20271', 'starts_on' => '2027-01-18', 'ends_on' => '2027-06-11']);
    $this->previous = Term::factory()->create(['code' => '20263', 'starts_on' => '2026-08-17', 'ends_on' => '2026-12-11']);
    $this->campus = Campus::factory()->create();
    $this->department = Department::factory()->create();
    $this->subject = Subject::factory()->create(['department_id' => $this->department->id]);
    $this->group = Group::factory()->create([
        'term_id' => $this->term->id,
        'campus_id' => $this->campus->id,
        'subject_id' => $this->subject->id,
    ]);
    $this->query = fn (?Group $group = null) => app(RankingInputsQuery::class)
        ->forGroups($this->term, collect([$group ?? $this->group]));
    $this->inputs = fn (): array => collect(($this->query)()[$this->group->id] ?? [])->keyBy('teacherId')->all();
    $this->adscribed = fn (array $teacher = []): Teacher => tap(Teacher::factory()->create($teacher), fn (Teacher $t) => TeacherDepartment::factory()->create(['teacher_id' => $t->id, 'department_id' => $this->department->id]));
});

it('Ranking docente: trae a los adscritos y a los preferentes de otro departamento, sin duplicados', function () {
    $own = ($this->adscribed)();
    TeacherSubjectQualification::factory()->for($own)->for($this->subject)->preferente()->create();
    $other = Teacher::factory()->create();
    TeacherDepartment::factory()->create(['teacher_id' => $other->id]);
    TeacherSubjectQualification::factory()->for($other)->for($this->subject)->preferente()->create();

    $inputs = ($this->inputs)();

    expect($inputs)->toHaveCount(2)
        ->and($inputs[$own->id]->fromOtherDepartment)->toBeFalse()
        ->and($inputs[$own->id]->qualification)->toBe(QualificationLevel::Preferente)
        ->and($inputs[$other->id]->fromOtherDepartment)->toBeTrue()
        ->and($inputs[$other->id]->name)->toBe($other->name);
});

it('Ranking docente: excluye al apto de otro departamento, a los inactivos, borrados y adscripciones borradas', function () {
    $apto = Teacher::factory()->create();
    TeacherSubjectQualification::factory()->for($apto)->for($this->subject)->apto()->create();
    ($this->adscribed)(['is_active' => false]);
    ($this->adscribed)()->delete();
    $noAdscription = ($this->adscribed)();
    $noAdscription->adscriptions()->delete();
    $inactivePreferente = Teacher::factory()->create(['is_active' => false]);
    TeacherSubjectQualification::factory()->for($inactivePreferente)->for($this->subject)->preferente()->create();

    expect(($this->inputs)())->toBeEmpty();
});

it('Ranking docente: las horas base salen de las plazas vigentes del periodo', function () {
    $teacher = ($this->adscribed)();
    TeacherPosition::factory()->for($teacher)->create(['base_hours' => 20]);
    TeacherPosition::factory()->for($teacher)->create(['kind' => PositionKind::Asignatura, 'base_hours' => 10]);

    expect(($this->inputs)()[$teacher->id]->baseHours)->toBe(30);
});

it('Ranking docente: el historial cuenta solo dentro de la ventana y sin veranos', function () {
    $teacher = ($this->adscribed)();
    $row = fn (string $code, string $group) => TeacherSubjectHistory::factory()->create([
        'teacher_id' => $teacher->id, 'subject_id' => $this->subject->id, 'term_code' => $code, 'group_code' => $group,
    ]);
    $row('20263', 'A'); // dentro
    $row('20241', 'B'); // dentro (sexto semestre)
    $row('20233', 'C'); // más viejo que la ventana
    $row('20262', 'D'); // verano
    $row('20261', 'E')->delete(); // borrado
    TeacherSubjectHistory::factory()->create(['teacher_id' => $teacher->id, 'term_code' => '20263']); // otra materia

    expect(($this->inputs)()[$teacher->id]->historyCount)->toBe(2);
});

it('Ranking docente: la evaluación es el promedio simple de los últimos semestres y null sin ninguna', function () {
    $rated = ($this->adscribed)();
    $unrated = ($this->adscribed)();
    TeacherEvaluation::factory()->for($rated)->estudiantil()->create(['term_code' => '20263', 'score' => 4.0]);
    TeacherEvaluation::factory()->for($rated)->departamental()->create(['term_code' => '20263', 'score' => 3.5]);
    TeacherEvaluation::factory()->for($rated)->estudiantil()->create(['term_code' => '20261', 'score' => 3.0]);
    TeacherEvaluation::factory()->for($rated)->estudiantil()->create(['term_code' => '20253', 'score' => 0.0]); // fuera
    TeacherEvaluation::factory()->for($unrated)->estudiantil()->create(['term_code' => '20253', 'score' => 5.0]);

    $inputs = ($this->inputs)();

    expect($inputs[$rated->id]->evaluation)->toBe(3.5)
        ->and($inputs[$unrated->id]->evaluation)->toBeNull();
});

it('Ranking docente: el puesto anterior es el mejor de la misma materia y campus en el semestre previo', function () {
    $teacher = ($this->adscribed)();
    $none = ($this->adscribed)();
    $previousGroup = fn (array $attributes = []) => Group::factory()->create($attributes + [
        'term_id' => $this->previous->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id,
    ]);
    StaffingRanking::factory()->create(['group_id' => $previousGroup()->id, 'teacher_id' => $teacher->id, 'rank' => 3]);
    StaffingRanking::factory()->create(['group_id' => $previousGroup()->id, 'teacher_id' => $teacher->id, 'rank' => 2]);
    StaffingRanking::factory()->create([
        'group_id' => $previousGroup(['campus_id' => Campus::factory()->create()->id])->id,
        'teacher_id' => $none->id, 'rank' => 1,
    ]);

    $inputs = ($this->inputs)();

    expect($inputs[$teacher->id]->previousRank)->toBe(2)
        ->and($inputs[$none->id]->previousRank)->toBeNull();
});

it('Ranking docente: sin periodo anterior registrado el puesto anterior es null', function () {
    $this->previous->forceDelete();
    $teacher = ($this->adscribed)();

    expect(($this->inputs)()[$teacher->id]->previousRank)->toBeNull();
});

it('Ranking docente: omite los grupos cancelados', function () {
    ($this->adscribed)();
    $cancelled = Group::factory()->cancelled()->create([
        'term_id' => $this->term->id, 'campus_id' => $this->campus->id, 'subject_id' => $this->subject->id,
    ]);

    $result = app(RankingInputsQuery::class)->forGroups($this->term, collect([$cancelled, $this->group]));

    expect($result)->toHaveKey($this->group->id)->not->toHaveKey($cancelled->id);
});

it('Ranking docente: convierte el insumo en candidato con las marcas dadas', function () {
    $teacher = ($this->adscribed)();
    TeacherSubjectQualification::factory()->for($teacher)->for($this->subject)->apto()->create();

    $candidate = ($this->inputs)()[$teacher->id]->toCandidate(true, false, true, 4);

    expect($candidate->teacherId)->toBe($teacher->id)
        ->and($candidate->qualification)->toBe(QualificationLevel::Apto)
        ->and($candidate->noHours)->toBeTrue()
        ->and($candidate->clash)->toBeFalse()
        ->and($candidate->unavailable)->toBeTrue()
        ->and($candidate->remainingHours)->toBe(4);
});

it('Ranking docente: usa las mismas consultas para 1 que para 10 grupos', function () {
    foreach (range(1, 3) as $i) {
        $teacher = ($this->adscribed)();
        TeacherPosition::factory()->for($teacher)->create();
        TeacherEvaluation::factory()->for($teacher)->estudiantil()->create(['term_code' => '20263']);
    }
    $count = function (int $groups): int {
        $set = collect([$this->group])->concat(Group::factory()->count($groups - 1)->create([
            'term_id' => $this->term->id, 'campus_id' => $this->campus->id,
        ]));
        DB::enableQueryLog();
        DB::flushQueryLog();
        app(RankingInputsQuery::class)->forGroups($this->term, $set);
        $total = count(DB::getQueryLog());
        DB::disableQueryLog();

        return $total;
    };

    $one = $count(1);

    expect($count(10))->toBe($one)->and($one)->toBeLessThanOrEqual(12);
});
