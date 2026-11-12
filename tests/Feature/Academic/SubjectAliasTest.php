<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectAlias;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\SubjectsTemplate;
use App\Modules\Students\Models\Student;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/*
| Plan actual, alias de materias y modalidad del alumno (tarea 5 de la migración del SII; D12 y D15, confirmados el
| 5 de noviembre de 2026). Una carrera tiene un solo plan actual; una materia se encuentra por la clave con que el
| SII la conoce; el alumno guarda su modalidad y el motivo del SII junto a su estatus.
*/

it('solo un plan actual por carrera', function () {
    $career = Career::factory()->create();
    StudyPlan::factory()->for($career)->create(['is_current' => true]);

    expect(fn () => DB::transaction(fn () => StudyPlan::factory()->for($career)->create(['is_current' => true])))->toThrow(UniqueConstraintViolationException::class);

    StudyPlan::factory()->for($career)->create(['is_current' => false]);
    StudyPlan::factory()->create(['is_current' => true]);
    StudyPlan::query()->where('career_id', $career->id)->where('is_current', true)->firstOrFail()->delete();
    StudyPlan::factory()->for($career)->create(['is_current' => true]);

    expect(StudyPlan::query()->where('career_id', $career->id)->where('is_current', true)->count())->toBe(1);
});

it('importar el alias de una materia la encuentra por su clave del SII', function () {
    $plan = StudyPlan::factory()->for(Career::factory()->create(['code' => 'ISIC']))->create(['code' => 'ISIC-2010-224']);
    Department::factory()->create(['code' => 'CB']);
    $template = new SubjectsTemplate;
    $row = $template->normalize([
        'plan' => 'isic-2010-224', 'code' => 'acf-0901', 'name' => 'Cálculo Diferencial', 'semester' => 1,
        'theory_hours' => 3, 'practice_hours' => 2, 'credits' => 5, 'department' => 'cb', 'sii_code' => 'ACF0901', 'kind' => 'Básica',
    ]);
    $context = new ImportContext(User::factory()->admin()->create());

    expect($template->validate($row, $context)->errors)->toBe([]);
    $template->apply($row, $context);

    $alias = SubjectAlias::query()->where('code', 'ACF0901')->firstOrFail();

    expect($alias->subject)->code->toBe('ACF-0901')->kind->value->toBe('basica')
        ->and($alias->study_plan_id)->toBe($plan->id)
        ->and($alias->source)->toBe('sii')
        ->and(Subject::query()->where('code', 'ACF-0901')->firstOrFail()->aliases)->toHaveCount(1);

    $template->apply($row, $context);

    expect(SubjectAlias::count())->toBe(1);
});

it('un alias no se repite', function () {
    $subject = Subject::factory()->create();
    SubjectAlias::create(['subject_id' => $subject->id, 'code' => 'ACF0901']);

    expect(fn () => SubjectAlias::create(['subject_id' => Subject::factory()->create()->id, 'code' => 'ACF0901']))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('el alumno guarda modalidad y motivo del SII', function () {
    $student = Student::factory()->inModality(Modality::query()->where('code', 'EAD')->firstOrFail())->create(['status_reason' => 'BT1']);

    expect($student->fresh())->modality->code->toBe('EAD')->status_reason->toBe('BT1')
        ->and(Student::factory()->create()->modality->code)->toBe('PRES');
});
