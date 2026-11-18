<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Database\Factories\LoadSnapshotFactory;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\AcademicLoad\Models\LoadSnapshot;
use App\Modules\AcademicLoad\Queries\ReanalysisDiffQuery;
use App\Modules\Core\Enums\SettingScope;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Setting;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Enums\DemandBalance;
use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Models\DemandForecast;
use Illuminate\Support\Facades\DB;

/*
| ReanalysisDiffQuery (tarea 8.2): la proyección vigente contra la oferta de la última versión publicada, por campus,
| carrera y materia. RA-04 (umbral y clases), RA-07 (filtro de carreras) y RA-09 (primer semestre sin clasificar).
*/

beforeEach(function () {
    $this->term = Term::factory()->create();
    $this->modality = modalityOf('PRES');
    $this->department = Department::factory()->create();
    $this->load = AcademicLoad::factory()->create([
        'term_id' => $this->term->id, 'modality_id' => $this->modality->id, 'department_id' => $this->department->id,
    ]);
    $this->campus = Campus::factory()->create(['code' => 'T2', 'name' => 'Campus de prueba']);
    $this->career = Career::factory()->create(['code' => 'ISC']);
    $this->subjectA = Subject::factory()->create(['department_id' => $this->department->id, 'code' => 'AAA-0001']);
    $this->subjectB = Subject::factory()->create(['department_id' => $this->department->id, 'code' => 'BBB-0002']);
});

/**
 * Una fila de la proyección de la carga para una materia (por omisión, 100 esperados y 3 grupos).
 *
 * @param  array<string, mixed>  $overrides
 */
function diffForecast(object $test, Subject $subject, array $overrides = [], ?Career $career = null): DemandForecast
{
    return DemandForecast::factory()->create([
        'term_id' => $test->term->id,
        'modality_id' => $test->modality->id,
        'campus_id' => $test->campus->id,
        'career_id' => ($career ?? $test->career)->id,
        'subject_id' => $subject->id,
        'expected_students' => 100,
        'suggested_groups' => 3,
        ...$overrides,
    ]);
}

/**
 * Un grupo del payload de la materia con un renglón por carrera y cupo dados.
 *
 * @param  list<array{0: Career, 1: int}>  $placements
 * @return array<string, mixed>
 */
function diffGroup(object $test, Subject $subject, array $placements, ?Campus $campus = null, int $semester = 4): array
{
    $campus ??= $test->campus;

    return LoadSnapshotFactory::group([
        'campus' => ['id' => $campus->id, 'code' => $campus->code, 'name' => $campus->name],
        'subject' => ['id' => $subject->id, 'code' => $subject->code, 'name' => $subject->name, 'credits' => 5, 'theory_hours' => 3, 'practice_hours' => 2, 'department_id' => $test->department->id],
        'placements' => array_map(fn (array $placement, int $i): array => LoadSnapshotFactory::placement([
            'career' => ['id' => $placement[0]->id, 'code' => $placement[0]->code, 'name' => $placement[0]->name],
            'semester' => $semester,
            'seats' => $placement[1],
            'is_owner' => $i === 0,
        ]), $placements, array_keys($placements)),
    ]);
}

/**
 * Publica una versión de la carga con esos grupos.
 *
 * @param  list<array<string, mixed>>  $groups
 */
function diffPublish(AcademicLoad $load, array $groups, int $version = 1): LoadSnapshot
{
    return LoadSnapshot::factory()->withGroups($groups)->create(['academic_load_id' => $load->id, 'version' => $version]);
}

it('RA-04: clasifica faltante, sobrante y ajustada con el umbral de la proyección', function () {
    $subjectC = Subject::factory()->create(['department_id' => $this->department->id, 'code' => 'CCC-0003']);
    diffForecast($this, $this->subjectA, ['expected_students' => 100]);
    diffForecast($this, $this->subjectB, ['expected_students' => 100]);
    diffForecast($this, $subjectC, ['expected_students' => 100]);
    diffPublish($this->load, [
        diffGroup($this, $this->subjectA, [[$this->career, 70]]),
        diffGroup($this, $this->subjectB, [[$this->career, 130]]),
        diffGroup($this, $subjectC, [[$this->career, 90]]),
    ]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);
    $byCode = collect($diff->rows)->keyBy('subjectCode');

    expect($byCode['AAA-0001']->balance)->toBe(DemandBalance::Shortfall)
        ->and($byCode['BBB-0002']->balance)->toBe(DemandBalance::Surplus)
        ->and($byCode['CCC-0003']->balance)->toBe(DemandBalance::Adjusted)
        ->and($diff->totals)->toBe(['shortfall' => 1, 'surplus' => 1, 'adjusted' => 1, 'unclassified' => 0])
        ->and($byCode['AAA-0001']->gap())->toBe(30);
});

it('RA-04: cada fila trae campus, carrera, materia, esperados, grupos sugeridos y lo ofrecido', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 80, 'suggested_groups' => 2]);
    diffPublish($this->load, [
        diffGroup($this, $this->subjectA, [[$this->career, 35]]),
        diffGroup($this, $this->subjectA, [[$this->career, 40]]),
    ]);

    $row = app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows[0];

    expect($row->campusCode)->toBe('T2')
        ->and($row->careerCode)->toBe('ISC')
        ->and($row->subjectCode)->toBe('AAA-0001')
        ->and($row->semester)->toBe(4)
        ->and($row->forecasted)->toBeTrue()
        ->and($row->expectedStudents)->toBe(80)
        ->and($row->suggestedGroups)->toBe(2)
        ->and($row->offeredSeats)->toBe(75)
        ->and($row->offeredGroups)->toBe(2)
        ->and($row->balance)->toBe(DemandBalance::Adjusted);
});

it('RA-04: un grupo compartido suma solo los cupos de cada carrera en su fila', function () {
    $other = Career::factory()->create(['code' => 'IGE']);
    diffForecast($this, $this->subjectA, ['expected_students' => 20]);
    diffForecast($this, $this->subjectA, ['expected_students' => 10], $other);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 20], [$other, 15]])]);

    $rows = collect(app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows)->keyBy('careerCode');

    expect($rows['ISC']->offeredSeats)->toBe(20)
        ->and($rows['ISC']->balance)->toBe(DemandBalance::Adjusted)
        ->and($rows['IGE']->offeredSeats)->toBe(15)
        ->and($rows['IGE']->offeredGroups)->toBe(1)
        ->and($rows['IGE']->balance)->toBe(DemandBalance::Surplus);
});

it('RA-04: una carrera con dos retículas en un grupo compartido cuenta sus lugares y el grupo una sola vez', function () {
    $other = Career::factory()->create(['code' => 'IGE']);
    diffForecast($this, $this->subjectA, ['expected_students' => 15], $other);
    $group = diffGroup($this, $this->subjectA, [[$this->career, 20], [$other, 15], [$other, 15]]);
    diffPublish($this->load, [$group]);

    $rows = collect(app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows)->keyBy('careerCode');

    expect($rows['IGE']->offeredSeats)->toBe(15)
        ->and($rows['IGE']->offeredGroups)->toBe(1)
        ->and($rows['IGE']->balance)->toBe(DemandBalance::Adjusted);
});

it('RA-07: la hora del cálculo de la proyección es la misma con o sin el filtro del coordinador', function () {
    $other = Career::factory()->create(['code' => 'IGE']);
    diffForecast($this, $this->subjectA, ['computed_at' => now()->subHour()]);
    diffForecast($this, $this->subjectA, ['computed_at' => now()], $other);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 20]])]);

    $query = app(ReanalysisDiffQuery::class);

    expect($query->forLoad($this->load, [$this->career->id])->forecastComputedAt?->toIso8601String())
        ->toBe($query->forLoad($this->load)->forecastComputedAt?->toIso8601String());
});

it('RA-04: la demanda sin oferta es faltante y la oferta sin demanda es sobrante', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 40]);
    diffPublish($this->load, [diffGroup($this, $this->subjectB, [[$this->career, 35]])]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);
    $byCode = collect($diff->rows)->keyBy('subjectCode');

    expect($diff->rows)->toHaveCount(2)
        ->and($byCode['AAA-0001']->offeredSeats)->toBe(0)
        ->and($byCode['AAA-0001']->offeredGroups)->toBe(0)
        ->and($byCode['AAA-0001']->balance)->toBe(DemandBalance::Shortfall)
        ->and($byCode['BBB-0002']->forecasted)->toBeFalse()
        ->and($byCode['BBB-0002']->expectedStudents)->toBe(0)
        ->and($byCode['BBB-0002']->offeredSeats)->toBe(35)
        ->and($byCode['BBB-0002']->balance)->toBe(DemandBalance::Surplus);
});

it('RA-04: sin ninguna versión publicada todo lo esperado es faltante', function () {
    diffForecast($this, $this->subjectA);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);

    expect($diff->snapshotVersion)->toBeNull()
        ->and($diff->rows[0]->balance)->toBe(DemandBalance::Shortfall);
});

it('RA-04: el umbral editado en Parámetros cambia la clase', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 100]);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 85]])]);

    expect(app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows[0]->balance)->toBe(DemandBalance::Adjusted);

    Setting::query()->create(['scope' => SettingScope::Global, 'scope_id' => null, 'key' => 'demand.highlight_threshold', 'value' => 10]);

    expect(app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows[0]->balance)->toBe(DemandBalance::Shortfall);
});

it('RA-09: una materia de primer semestre se muestra con su nuevo ingreso y sin clase', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 200])
        ->forceFill(['method' => ForecastMethod::FirstSemester, 'new_students' => 180])->save();
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 35]], semester: 1)]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);

    expect($diff->rows[0]->isFirstSemester)->toBeTrue()
        ->and($diff->rows[0]->newStudents)->toBe(180)
        ->and($diff->rows[0]->balance)->toBeNull()
        ->and($diff->rows[0]->offeredSeats)->toBe(35)
        ->and($diff->totals)->toBe(['shortfall' => 0, 'surplus' => 0, 'adjusted' => 0, 'unclassified' => 1])
        ->and($diff->rowsOf(null))->toHaveCount(1);
});

it('RA-09: una oferta sin proyección de una materia de primer semestre en el plan vigente tampoco se clasifica', function () {
    $plan = StudyPlan::factory()->create(['career_id' => $this->career->id, 'is_current' => true]);
    StudyPlanSubject::factory()->create(['study_plan_id' => $plan->id, 'subject_id' => $this->subjectA->id, 'semester' => 1]);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 35]], semester: 3)]);

    $row = app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows[0];

    expect($row->semester)->toBe(1)
        ->and($row->isFirstSemester)->toBeTrue()
        ->and($row->balance)->toBeNull();
});

it('marca las materias por revisar y las que se proyectaron sin historia suficiente', function () {
    $subjectC = Subject::factory()->create(['department_id' => $this->department->id, 'code' => 'CCC-0003']);
    diffForecast($this, $this->subjectA, ['needs_review' => true]);
    diffForecast($this, $this->subjectB)->forceFill(['method' => ForecastMethod::DepartmentFallback, 'history_terms_used' => 2])->save();
    diffForecast($this, $subjectC);

    $rows = collect(app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows)->keyBy('subjectCode');

    expect($rows['AAA-0001']->needsReview)->toBeTrue()
        ->and($rows['AAA-0001']->insufficientHistory)->toBeFalse()
        ->and($rows['BBB-0002']->needsReview)->toBeFalse()
        ->and($rows['BBB-0002']->insufficientHistory)->toBeTrue()
        ->and($rows['CCC-0003']->needsReview)->toBeFalse()
        ->and($rows['CCC-0003']->insufficientHistory)->toBeFalse();
});

it('RA-07: limita las filas a las carreras que se piden, en la proyección y en la oferta', function () {
    $other = Career::factory()->create(['code' => 'IGE']);
    diffForecast($this, $this->subjectA);
    diffForecast($this, $this->subjectA, [], $other);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 35], [$other, 35]]), diffGroup($this, $this->subjectB, [[$other, 20]])]);

    $query = app(ReanalysisDiffQuery::class);

    expect(collect($query->forLoad($this->load, [$other->id])->rows)->pluck('careerCode')->unique()->all())->toBe(['IGE'])
        ->and($query->forLoad($this->load, [$other->id])->rows)->toHaveCount(2)
        ->and($query->forLoad($this->load, [$this->career->id])->rows)->toHaveCount(1)
        ->and($query->forLoad($this->load, [])->rows)->toBe([])
        ->and($query->forLoad($this->load)->rows)->toHaveCount(3);
});

it('compara contra la última versión publicada', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 100]);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 100]])], 1);
    diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 40]])], 2);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);

    expect($diff->snapshotVersion)->toBe(2)
        ->and($diff->rows[0]->offeredSeats)->toBe(40)
        ->and($diff->rows[0]->balance)->toBe(DemandBalance::Shortfall);
});

it('lee también una versión publicada con la forma anterior', function () {
    diffForecast($this, $this->subjectA, ['expected_students' => 35]);
    $snapshot = diffPublish($this->load, [diffGroup($this, $this->subjectA, [[$this->career, 35]])]);
    $payload = $snapshot->payload;
    $payload['schema_version'] = 1;
    $payload['campus'] = $payload['groups'][0]['campus'];
    unset($payload['modality']);
    $payload['groups'] = array_map(function (array $group): array {
        unset($group['campus']);

        return $group;
    }, $payload['groups']);
    DB::statement('alter table load_snapshots disable trigger user');
    DB::table('load_snapshots')->where('id', $snapshot->id)->update(['payload' => json_encode($payload)]);
    DB::statement('alter table load_snapshots enable trigger user');

    $row = app(ReanalysisDiffQuery::class)->forLoad($this->load)->rows[0];

    expect($row->campusCode)->toBe('T2')
        ->and($row->offeredSeats)->toBe(35)
        ->and($row->balance)->toBe(DemandBalance::Adjusted);
});

it('ignora otros departamentos, otras modalidades y otros periodos', function () {
    $foreignSubject = Subject::factory()->create();
    diffForecast($this, $this->subjectA);
    diffForecast($this, $foreignSubject);
    DemandForecast::factory()->create(['term_id' => Term::factory(), 'modality_id' => $this->modality->id, 'campus_id' => $this->campus->id, 'career_id' => $this->career->id, 'subject_id' => $this->subjectB->id]);
    DemandForecast::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('EAD')->id, 'campus_id' => null, 'career_id' => $this->career->id, 'subject_id' => $this->subjectB->id]);

    $otherLoad = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => $this->modality->id, 'department_id' => Department::factory()]);
    diffPublish($otherLoad, [diffGroup($this, $foreignSubject, [[$this->career, 50]])]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);

    expect($diff->rows)->toHaveCount(1)
        ->and($diff->rows[0]->subjectCode)->toBe('AAA-0001')
        ->and($diff->rows[0]->offeredSeats)->toBe(0);
});

it('una modalidad sin salones tiene una sola columna sin campus', function () {
    $load = AcademicLoad::factory()->create(['term_id' => $this->term->id, 'modality_id' => modalityOf('EAD')->id, 'department_id' => $this->department->id]);
    DemandForecast::factory()->withoutCampus()->create(['term_id' => $this->term->id, 'modality_id' => $load->modality_id, 'career_id' => $this->career->id, 'subject_id' => $this->subjectA->id, 'expected_students' => 30]);
    $group = diffGroup($this, $this->subjectA, [[$this->career, 30]]);
    $group['campus'] = null;
    diffPublish($load, [$group]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($load);

    expect($diff->rows)->toHaveCount(1)
        ->and($diff->rows[0]->campusId)->toBeNull()
        ->and($diff->rows[0]->offeredSeats)->toBe(30)
        ->and($diff->rows[0]->balance)->toBe(DemandBalance::Adjusted);
});

it('guarda el cálculo más reciente de la proyección y ordena por campus, carrera y materia', function () {
    $second = Campus::factory()->create(['code' => 'T1', 'name' => 'Campus uno']);
    diffForecast($this, $this->subjectB, ['computed_at' => now()->subDay()]);
    diffForecast($this, $this->subjectA, ['computed_at' => now()->subHour()]);
    diffForecast($this, $this->subjectA, ['campus_id' => $second->id, 'computed_at' => now()->subDays(2)]);

    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);

    expect(collect($diff->rows)->map(fn ($row): string => "{$row->campusCode}/{$row->subjectCode}")->all())->toBe(['T1/AAA-0001', 'T2/AAA-0001', 'T2/BBB-0002'])
        ->and($diff->forecastComputedAt->diffInMinutes(now()->subHour(), true))->toBeLessThan(1);
});

it('lee con un número fijo de consultas sin importar cuántas filas tenga', function () {
    $careers = Career::factory()->count(6)->create();
    $campuses = Campus::factory()->count(2)->create();
    $groups = [];

    foreach (Subject::factory()->count(30)->create(['department_id' => $this->department->id]) as $subject) {
        foreach ($careers as $career) {
            foreach ($campuses as $campus) {
                DemandForecast::factory()->create([
                    'term_id' => $this->term->id, 'modality_id' => $this->modality->id, 'campus_id' => $campus->id,
                    'career_id' => $career->id, 'subject_id' => $subject->id, 'expected_students' => 50,
                ]);
                $groups[] = diffGroup($this, $subject, [[$career, 45]], $campus);
            }
        }
    }

    diffPublish($this->load, $groups);

    DB::enableQueryLog();
    $diff = app(ReanalysisDiffQuery::class)->forLoad($this->load);
    $queries = count(DB::getQueryLog());
    DB::disableQueryLog();

    expect($diff->rows)->toHaveCount(30 * 6 * 2)
        ->and($queries)->toBeLessThanOrEqual(5);
});

it('no escribe nada: el estado de la carga y sus versiones quedan igual', function () {
    $load = $this->load;
    $load->update(['status' => LoadStatus::Reanalysis]);
    diffPublish($load, [diffGroup($this, $this->subjectA, [[$this->career, 35]])]);

    app(ReanalysisDiffQuery::class)->forLoad($load);

    expect($load->fresh()->status)->toBe(LoadStatus::Reanalysis)
        ->and(LoadSnapshot::query()->where('academic_load_id', $load->id)->count())->toBe(1);
});
