<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Models\CareerProposal;
use App\Modules\AcademicLoad\Models\ProposalItem;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;
use App\Modules\Students\Queries\EligibleStudentsQuery;
use Illuminate\Support\Facades\DB;

/*
| «Alumnos posibles» de las propuestas que ya existían (docs/dominio/modelo-de-datos.md, "AcademicLoad"): la
| migración los calcula una vez con los mismos filtros de EligibleStudentsQuery::distinctFor sobre sus filas, sin
| los alumnos borrados, inactivos o que no son elegibles para ninguna de sus materias.
*/
it('calcula los alumnos posibles de las propuestas que ya existen como EligibleStudentsQuery::distinctFor', function () {
    // La propuesta por carrera y modalidad (posterior) quita career_proposals.campus_id y lee el puente de campus a
    // modalidad, que quitó la contracción: se deshacen primero, de la última a la primera.
    (require base_path('app/Modules/Core/Database/Migrations/2026_11_12_211102_contract_campuses_to_places.php'))->down();
    (require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_12_210601_make_career_proposals_per_modality.php'))->down();
    $plan = StudyPlan::factory()->create();
    $campus = campusBeforeContraction('C9');
    $calculo = Subject::factory()->create();
    $fisica = Subject::factory()->create();
    $outside = Subject::factory()->create();
    $proposal = CareerProposal::factory()->create(['career_id' => $plan->career_id, 'campus_id' => $campus->id]);
    foreach ([$calculo, $fisica] as $subject) {
        ProposalItem::factory()->create(['career_proposal_id' => $proposal->id, 'subject_id' => $subject->id, 'study_plan_id' => $plan->id]);
    }
    $student = fn (array $subjects, array $attributes = []) => StudentProgress::factory()
        ->for(Student::factory()->for($plan)->create(['campus_id' => $campus->id, ...$attributes]))
        ->create(['subjects_available' => array_map(fn (Subject $subject): string => $subject->id, $subjects)]);
    $student([$calculo, $fisica]);
    $student([$fisica]);
    $student([$calculo])->student->delete();
    $student([$calculo], ['status' => StudentStatus::BajaDefinitiva]);
    $student([$outside]);
    $empty = CareerProposal::factory()->create(['campus_id' => $campus->id]);

    $migration = require base_path('app/Modules/AcademicLoad/Database/Migrations/2026_11_06_000002_add_eligible_students_to_career_proposals.php');
    $migration->down();
    $migration->up();

    $expected = app(EligibleStudentsQuery::class)->distinctFor($proposal->career, modalityOf('PRES'), $campus, [$calculo->id, $fisica->id]);

    expect($expected)->toBe(2)
        ->and(DB::table('career_proposals')->where('id', $proposal->id)->value('eligible_students'))->toBe($expected)
        ->and(DB::table('career_proposals')->where('id', $empty->id)->value('eligible_students'))->toBe(0);
});
