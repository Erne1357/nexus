<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Jobs\RunForecastEvaluation;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use Illuminate\Support\Facades\Queue;

/*
| `academic-load:evaluate-forecast` (tarea 5.9b): evalúa la proyección de un periodo en el mismo proceso e imprime, por
| carrera y campus, el error ponderado, el de R1 y la confianza (DM-22), con la línea global; con `--queue` solo la
| encola. Sin `--modality`, la única modalidad activa con kárdex del periodo.
|
| Fixture: ISX en K1, B (2.º semestre) pide A. Diez alumnos aprobaron A en 20253 y los diez llevan B en 20261: sin
| historia, la proyección «como si» espera a los diez elegibles, igual que R1.
*/

beforeEach(function () {
    $this->term = Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026']);
    $campus = Campus::factory()->create(['code' => 'K1']);
    $plan = StudyPlan::factory()->create();
    $plan->career->update(['code' => 'ISX']);
    $a = Subject::factory()->create();
    $b = Subject::factory()->create();
    placeInPlan($plan, $a);
    placeInPlan($plan, $b, 2);
    seriate($plan, $b, $a);
    CareerCampus::factory()->create(['career_id' => $plan->career_id, 'campus_id' => $campus->id]);

    foreach (range(1, 10) as $i) {
        $student = Student::factory()->create(['study_plan_id' => $plan->id, 'campus_id' => $campus->id]);
        KardexRecord::factory()->for($student)->for($a)->create(['term_code' => '20253']);
        KardexRecord::factory()->for($student)->for($b)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    }
});

it('el comando imprime la confianza por carrera', function () {
    $this->artisan('academic-load:evaluate-forecast', ['term' => '20261'])
        ->expectsTable(['Carrera', 'Campus', 'Error ponderado', 'Error R1', 'Confianza'], [['ISX', 'K1', '0.0 %', '0.0 %', 'Sugerencia fuerte']])
        ->expectsOutputToContain('Global: error ponderado 0.0 %, error R1 0.0 %, Sugerencia fuerte.')
        ->assertSuccessful();

    expect(ForecastEvaluation::query()->sole())
        ->status->toBe(ForecastEvaluationStatus::Done)
        ->requested_by->toBeNull()
        ->modality_id->toBe(modalityOf('PRES')->id);
});

it('con --queue solo encola la evaluación', function () {
    Queue::fake();

    $this->artisan('academic-load:evaluate-forecast', ['term' => '20261', '--modality' => 'PRES', '--campus' => 'K1', '--queue' => true])
        ->expectsOutputToContain('Evaluación de Ene-Jun 2026 en cola.')
        ->assertSuccessful();

    $evaluation = ForecastEvaluation::query()->sole();

    expect($evaluation->status)->toBe(ForecastEvaluationStatus::Queued)
        ->and($evaluation->campus->code)->toBe('K1');
    Queue::assertPushed(RunForecastEvaluation::class);
});

it('el comando avisa sin evaluar si el periodo no existe, no tiene kárdex o hay varias modalidades con kárdex', function (array $arguments, string $error) {
    Term::factory()->create(['code' => '20271', 'name' => 'Ene-Jun 2027']);
    $other = Modality::factory()->create(['code' => 'M903']);
    $student = Student::factory()->inModality($other)->create();
    KardexRecord::factory()->for($student)->create(['term_code' => '20261']);

    $this->artisan('academic-load:evaluate-forecast', $arguments)
        ->expectsOutputToContain($error)
        ->assertFailed();

    expect(ForecastEvaluation::query()->count())->toBe(0);
})->with([
    'periodo que no existe' => [['term' => '20991'], 'No existe el periodo 20991.'],
    'sin kárdex' => [['term' => '20271', '--modality' => 'PRES'], 'No hay kárdex de Ene-Jun 2027: no hay con qué comparar.'],
    'varias modalidades' => [['term' => '20261'], 'Hay varias modalidades activas con kárdex de Ene-Jun 2026: elige una con --modality.'],
    'modalidad que no existe' => [['term' => '20261', '--modality' => 'NOPE'], 'No existe la modalidad NOPE.'],
]);
