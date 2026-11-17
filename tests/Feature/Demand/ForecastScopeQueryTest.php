<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Demand\Queries\ForecastScopeQuery;

/*
| ForecastScopeQuery dice qué cubre la proyección: las carreras con oferta activa, las columnas por modalidad y las
| materias de la retícula, igual que la propuesta de carrera (HC-13; confirmado el 2 de noviembre de 2026).
*/

function scopeOffer(Career $career, Campus $campus, array $state = []): CareerCampus
{
    return CareerCampus::factory()->create(['career_id' => $career->id, 'campus_id' => $campus->id, ...$state]);
}

/**
 * @return list<string>
 */
function scopeSubjectCodes(Career $career): array
{
    return (new ForecastScopeQuery)->subjectsFor($career)
        ->map(fn (object $row): string => Subject::findOrFail($row->subject_id)->code)->all();
}

it('lista las carreras con oferta activa en un campus activo, por clave', function () {
    $campus = Campus::factory()->create();
    $b = Career::factory()->create(['code' => 'BBB']);
    $a = Career::factory()->create(['code' => 'AAA']);
    Career::factory()->create(['code' => 'CCC']);
    scopeOffer($b, $campus);
    scopeOffer($a, $campus);

    expect((new ForecastScopeQuery)->careers()->pluck('code')->all())->toBe(['AAA', 'BBB']);
});

it('deja fuera la carrera con oferta inactiva, borrada o en un campus inactivo', function () {
    $campus = Campus::factory()->create();
    $inactiveCampus = Campus::factory()->create(['is_active' => false]);
    $ok = Career::factory()->create(['code' => 'OK']);
    $off = Career::factory()->create(['code' => 'OFF']);
    $gone = Career::factory()->create(['code' => 'GONE']);
    $closed = Career::factory()->create(['code' => 'CLOSED']);
    scopeOffer($ok, $campus);
    scopeOffer($off, $campus, ['is_active' => false]);
    scopeOffer($gone, $campus)->delete();
    scopeOffer($closed, $inactiveCampus);

    expect((new ForecastScopeQuery)->careers()->pluck('code')->all())->toBe(['OK']);
});

it('deja fuera la carrera inactiva o borrada', function () {
    $campus = Campus::factory()->create();
    $inactive = Career::factory()->create(['code' => 'INA', 'is_active' => false]);
    $deleted = Career::factory()->create(['code' => 'DEL']);
    scopeOffer($inactive, $campus);
    scopeOffer($deleted, $campus);
    $deleted->delete();

    expect((new ForecastScopeQuery)->careers())->toBeEmpty();
});

it('da un campus por columna en una modalidad con salones y una columna sin campus en una sin salones', function () {
    $career = Career::factory()->create();
    $c2 = Campus::factory()->create(['code' => 'C2']);
    $c1 = Campus::factory()->create(['code' => 'C1']);
    $off = Campus::factory()->create(['code' => 'C0', 'is_active' => false]);
    $other = Campus::factory()->create(['code' => 'C9']);
    scopeOffer($career, $c2);
    scopeOffer($career, $c1);
    scopeOffer($career, $off);
    scopeOffer($career, $other, ['is_active' => false]);
    $online = Modality::factory()->create(['code' => 'M901', 'settings' => ['uses_rooms' => false]]);

    $query = new ForecastScopeQuery;

    expect(array_map(fn (?Campus $campus): ?string => $campus?->code, $query->campusesFor(modalityOf('PRES'), $career)))->toBe(['C1', 'C2'])
        ->and($query->campusesFor($online, $career))->toBe([null]);
});

it('toma las materias activas de los planes activos, una vez cada una y del primer plan por clave', function () {
    $career = Career::factory()->create();
    $first = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'A-PLAN']);
    $second = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'B-PLAN']);
    $shared = Subject::factory()->create(['code' => 'SHARED', 'needs_lab' => true]);
    placeInPlan($second, $shared, 2);
    placeInPlan($first, $shared, 5);

    $rows = (new ForecastScopeQuery)->subjectsFor($career);

    expect($rows)->toHaveCount(1)
        ->and($rows[0]->study_plan_id)->toBe($first->id)
        ->and($rows[0]->semester)->toBe(5)
        ->and($rows[0]->needs_lab)->toBeTrue();
});

it('excluye las materias inactivas o borradas y los planes inactivos o borrados', function () {
    $career = Career::factory()->create();
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $inactivePlan = StudyPlan::factory()->create(['career_id' => $career->id, 'is_active' => false]);
    $deletedPlan = StudyPlan::factory()->create(['career_id' => $career->id]);
    placeInPlan($plan, Subject::factory()->create(['code' => 'OK-1']));
    placeInPlan($plan, Subject::factory()->inactive()->create(['code' => 'INACTIVE']));
    $deletedSubject = Subject::factory()->create(['code' => 'DELETED']);
    placeInPlan($plan, $deletedSubject);
    $deletedSubject->delete();
    placeInPlan($inactivePlan, Subject::factory()->create(['code' => 'IN-PLAN']));
    placeInPlan($deletedPlan, Subject::factory()->create(['code' => 'DEL-PLAN']));
    $deletedPlan->delete();

    expect(scopeSubjectCodes($career))->toBe(['OK-1']);
});

it('excluye residencia, servicio social, complementarias y materias sin horas, pero no las de tipo sin capturar', function () {
    $career = Career::factory()->create();
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-BASIC']));
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-NULL', 'kind' => null]));
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-RES', 'kind' => SubjectKind::Residencia]));
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-SS', 'kind' => SubjectKind::ServicioSocial]));
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-COMP', 'kind' => SubjectKind::Complementaria]));
    placeInPlan($plan, Subject::factory()->create(['code' => 'K-ZERO', 'theory_hours' => 0, 'practice_hours' => 0]));

    expect(scopeSubjectCodes($career))->toBe(['K-BASIC', 'K-NULL']);
});

it('trae el departamento de la materia y si la retícula la pone en una especialidad', function () {
    $career = Career::factory()->create();
    $plan = StudyPlan::factory()->create(['career_id' => $career->id]);
    $department = Department::factory()->create();
    $specialty = Specialty::factory()->for($plan)->create();
    placeInPlan($plan, Subject::factory()->create(['code' => 'S-COMMON', 'department_id' => $department->id]), 1);
    placeInPlan($plan, Subject::factory()->create(['code' => 'S-SPEC', 'department_id' => $department->id]), 2, $specialty);

    $rows = (new ForecastScopeQuery)->subjectsFor($career);

    expect($rows->pluck('is_specialty')->all())->toBe([false, true])
        ->and($rows->pluck('department_id')->unique()->all())->toBe([$department->id]);
});

it('DM-05: dice en qué semestre pone la materia el plan vigente de la carrera, sin cambiar qué fila se elige', function () {
    $career = Career::factory()->create();
    $old = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'A-OLD']);
    $current = StudyPlan::factory()->create(['career_id' => $career->id, 'code' => 'B-NEW', 'is_current' => true]);
    $shared = Subject::factory()->create(['code' => 'SHARED']);
    $oldOnly = Subject::factory()->create(['code' => 'OLD-ONLY']);
    $currentOnly = Subject::factory()->create(['code' => 'NEW-ONLY']);
    placeInPlan($old, $shared, 2);
    placeInPlan($current, $shared, 1);
    placeInPlan($old, $oldOnly, 1);
    placeInPlan($current, $currentOnly, 1);

    $rows = (new ForecastScopeQuery)->subjectsFor($career)->keyBy(fn (object $row): string => Subject::findOrFail($row->subject_id)->code);

    expect($rows['SHARED'])
        ->study_plan_id->toBe($old->id)
        ->semester->toBe(2)
        ->current_plan_semester->toBe(1)
        ->and($rows['OLD-ONLY']->current_plan_semester)->toBeNull()
        ->and($rows['NEW-ONLY']->current_plan_semester)->toBe(1);
});
