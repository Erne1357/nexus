<?php

use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus;
use App\Modules\AcademicLoad\Models\ForecastEvaluation;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;

/*
| «Precisión de la proyección» en el navegador (tarea 5.9c): Subdirección entra sin evaluaciones, abre «Evaluar un
| periodo», lanza la de Ene-Jun 2026 (la cola corre en sync en pruebas) y ve el resultado contra la meta y las
| materias de la carrera, a 1280 y a 390 px, sin desbordar la página.
*/

beforeEach(function () {
    Term::factory()->create(['code' => '20261', 'name' => 'Ene-Jun 2026']);
    $campus = Campus::factory()->create(['code' => 'K1']);
    $plan = StudyPlan::factory()->create();
    $plan->career->update(['code' => 'ISX', 'name' => 'Ingeniería de Prueba']);
    $subject = Subject::factory()->create(['code' => 'PA-1']);
    placeInPlan($plan, $subject);
    CareerCampus::factory()->create(['career_id' => $plan->career_id, 'campus_id' => $campus->id]);
    $student = Student::factory()->create(['study_plan_id' => $plan->id, 'career_id' => $plan->career_id, 'campus_id' => $campus->id, 'modality_id' => modalityOf('PRES')->id]);
    KardexRecord::factory()->for($student)->for($subject)->create(['term_code' => '20261', 'result' => KardexResult::Cursando, 'grade' => null]);
    User::factory()->withRole(Role::Subdireccion)->create(['username' => 'subdir.precision']);
});

it('Subdirección lanza una evaluación y ve el resultado a 1280 y a 390 px', function () {
    $page = visit('/login')
        ->resize(1280, 900)
        ->fill('username', 'subdir.precision')
        ->fill('password', 'password')
        ->press('Entrar')
        ->assertPathIs('/')
        ->navigate('/academic-load/forecast-accuracy')
        ->assertSee('Todavía no hay evaluaciones')
        ->click('@open-evaluate')
        ->waitForText('Nexus calcula la proyección como si corriera antes del periodo')
        ->assertSeeIn('@new-terms', 'Ene-Jun 2026')
        ->click('@submit-evaluate')
        ->waitForText('Evaluación lista')
        ->assertSeeIn('@evaluation-strip', 'Ene-Jun 2026')
        ->assertSeeIn('@summary-block', 'Todo el Tec')
        ->assertPresent('@career-ISX')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'forecast-accuracy-1280')
        ->assertNoJavaScriptErrors();

    expect(ForecastEvaluation::query()->sole()->status)->toBe(ForecastEvaluationStatus::Done);

    $page->resize(390, 844)
        ->assertSeeIn('@summary-block', 'Todo el Tec')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'forecast-accuracy-390')
        ->click('@career-ISX')
        ->waitForText('Materias por error')
        ->assertSeeIn('@subjects-list', 'PA-1')
        ->assertScript('document.documentElement.scrollWidth <= window.innerWidth')
        ->screenshot(filename: 'forecast-accuracy-subjects-390')
        ->assertNoJavaScriptErrors();
});
