<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Queries\Occupancy;
use App\Modules\Demand\Queries\TermOccupancyQuery;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\DB;

/*
| TermOccupancyQuery cuenta, por materia y periodo, los alumnos que la tomaron (aprobada, reprobada o cursando; la baja
| no cuenta), con el campus y la modalidad actuales del alumno. Alimenta la evaluación del pronóstico y la columna
| histórica de la propuesta.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create();
    $this->campus = Campus::factory()->create();
    $this->pres = modalityOf('PRES');
    $this->subject = Subject::factory()->create();
    $this->query = new TermOccupancyQuery;
});

/**
 * Un alumno del plan de la prueba, en el campus y la modalidad indicados (por omisión, los de la prueba).
 *
 * @param  array<string, mixed>  $attributes
 */
function occupancyStudent(?Campus $campus = null, ?Modality $modality = null, array $attributes = []): Student
{
    return Student::factory()->create([
        'campus_id' => ($campus ?? test()->campus)->id,
        'modality_id' => ($modality ?? test()->pres)->id,
        'study_plan_id' => test()->plan->id,
        ...$attributes,
    ]);
}

/**
 * Una fila del kárdex del alumno en la materia y el periodo.
 *
 * @param  array<string, mixed>  $attributes
 */
function occupancyRecord(Student $student, Subject $subject, ?string $term, KardexResult $result = KardexResult::Aprobada, array $attributes = []): KardexRecord
{
    return KardexRecord::factory()->for($student)->for($subject)->create([
        'term_code' => $term,
        'result' => $result,
        'grade' => $result === KardexResult::Aprobada ? 85 : null,
        ...$attributes,
    ]);
}

it('cuenta aprobados, reprobados y cursando de la materia en el periodo', function () {
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Aprobada);
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Aprobada);
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Reprobada);
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Cursando);

    $rows = $this->query->forTerm('20271', $this->pres);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['subject_id'])->toBe($this->subject->id)
        ->and($rows[0]['campus_id'])->toBe($this->campus->id)
        ->and($rows[0]['occupancy'])->toEqual(new Occupancy(students: 4, passed: 2, failed: 1, inProgress: 1));
});

it('no cuenta las bajas como ocupación', function () {
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Baja);
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Cursando);

    $rows = $this->query->forTerm('20271', $this->pres);

    expect($rows[0]['occupancy'])->toEqual(new Occupancy(students: 1, passed: 0, failed: 0, inProgress: 1));
});

it('una materia que solo tiene bajas no aparece', function () {
    occupancyRecord(occupancyStudent(), $this->subject, '20271', KardexResult::Baja);

    expect($this->query->forTerm('20271', $this->pres))->toBe([]);
});

it('cuenta a cada alumno una vez por periodo y no mezcla periodos', function () {
    $student = occupancyStudent();
    occupancyRecord($student, $this->subject, '20271', KardexResult::Reprobada);
    occupancyRecord($student, $this->subject, '20273', KardexResult::Aprobada);

    $rows = $this->query->forTerm('20271', $this->pres);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['occupancy'])->toEqual(new Occupancy(students: 1, passed: 0, failed: 1, inProgress: 0));
});

it('separa por carrera, campus y materia, en ese orden', function () {
    $other = Campus::factory()->create();
    $second = Subject::factory()->create();
    $a = occupancyStudent();
    $b = occupancyStudent($other);
    occupancyRecord($a, $this->subject, '20271');
    occupancyRecord($a, $second, '20271');
    occupancyRecord($b, $this->subject, '20271');

    $rows = $this->query->forTerm('20271', $this->pres);

    expect($rows)->toHaveCount(3);

    $keys = array_map(fn (array $row): string => $row['career_id'].'|'.$row['campus_id'].'|'.$row['subject_id'], $rows);
    $sorted = $keys;
    sort($sorted, SORT_STRING);
    expect($keys)->toBe($sorted);
});

it('filtra por la modalidad del alumno', function () {
    $other = Modality::factory()->create();
    occupancyRecord(occupancyStudent(), $this->subject, '20271');
    occupancyRecord(occupancyStudent(modality: $other), $this->subject, '20271');

    $rows = $this->query->forTerm('20271', $other);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['occupancy']->students)->toBe(1);
});

it('filtra por campus cuando se pide y suma todos cuando no', function () {
    $other = Campus::factory()->create();
    occupancyRecord(occupancyStudent(), $this->subject, '20271');
    occupancyRecord(occupancyStudent($other), $this->subject, '20271');

    $one = $this->query->forTerm('20271', $this->pres, $other);
    $all = $this->query->forTerm('20271', $this->pres);

    expect($one)->toHaveCount(1)
        ->and($one[0]['campus_id'])->toBe($other->id)
        ->and($all)->toHaveCount(2);
});

it('ignora filas borradas, alumnos borrados y filas sin periodo', function () {
    occupancyRecord(occupancyStudent(), $this->subject, '20271');
    occupancyRecord(occupancyStudent(), $this->subject, '20271')->delete();
    $gone = occupancyStudent();
    occupancyRecord($gone, $this->subject, '20271');
    $gone->delete();
    occupancyRecord(occupancyStudent(), $this->subject, null);

    $rows = $this->query->forTerm('20271', $this->pres);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['occupancy']->students)->toBe(1)
        ->and($this->query->history('20273', $this->pres, [$this->subject->id], 4))->toBe([]);
});

it('da el histórico de los periodos del mismo tipo dentro de la ventana, por materia, periodo y campus', function () {
    $other = Campus::factory()->create();
    $a = occupancyStudent();
    $b = occupancyStudent($other);
    occupancyRecord($a, $this->subject, '20261', KardexResult::Aprobada);
    occupancyRecord($b, $this->subject, '20261', KardexResult::Reprobada);
    occupancyRecord($a, $this->subject, '20251', KardexResult::Cursando);
    occupancyRecord($a, $this->subject, '20263');
    occupancyRecord($a, $this->subject, '20271');
    occupancyRecord($a, $this->subject, '20231');

    $history = $this->query->history('20271', $this->pres, [$this->subject->id], 2);

    expect($history)->toEqual([
        $this->subject->id => [
            '20261' => [
                $this->campus->id => new Occupancy(1, 1, 0, 0),
                $other->id => new Occupancy(1, 0, 1, 0),
            ],
            '20251' => [
                $this->campus->id => new Occupancy(1, 0, 0, 1),
            ],
        ],
    ]);
});

it('con una lista de materias vacía no consulta y da vacío', function () {
    occupancyRecord(occupancyStudent(), $this->subject, '20261');

    DB::enableQueryLog();
    $history = $this->query->history('20271', $this->pres, [], 4);

    expect($history)->toBe([])
        ->and(DB::getQueryLog())->toBe([]);
});

it('el histórico solo trae las materias pedidas y la modalidad pedida', function () {
    $other = Subject::factory()->create();
    $modality = Modality::factory()->create();
    occupancyRecord(occupancyStudent(), $this->subject, '20261');
    occupancyRecord(occupancyStudent(), $other, '20261');
    occupancyRecord(occupancyStudent(modality: $modality), $this->subject, '20261');

    $history = $this->query->history('20271', $this->pres, [$this->subject->id], 1);

    expect(array_keys($history))->toBe([$this->subject->id])
        ->and($history[$this->subject->id]['20261'][$this->campus->id]->students)->toBe(1);
});
