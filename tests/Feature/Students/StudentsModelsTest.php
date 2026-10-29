<?php

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Enums\KardexResult;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Models\StudentProgress;

/*
| Relaciones y conversiones de alumnos, kardex y progreso (tarea 1.4). Las relaciones solo apuntan dentro de
| Students o hacia Academic y Core.
*/

it('un alumno da su carrera, su plan, su campus, su kardex y su progreso', function () {
    $student = Student::factory()->create(['status' => StudentStatus::BajaTemporal]);
    $record = KardexRecord::factory()->for($student)->create();
    $progress = StudentProgress::factory()->for($student)->create();

    expect($student->studyPlan->career_id)->toBe($student->career_id)
        ->and($student->career->is($student->studyPlan->career))->toBeTrue()
        ->and($student->campus)->toBeInstanceOf(Campus::class)
        ->and($student->kardexRecords->modelKeys())->toBe([$record->id])
        ->and($student->progress->is($progress))->toBeTrue()
        ->and($progress->study_plan_id)->toBe($student->study_plan_id)
        ->and($student->fresh()->status)->toBe(StudentStatus::BajaTemporal);
});

it('una fila de kardex da su alumno y su materia, con el resultado y la calificación convertidos', function () {
    $record = KardexRecord::factory()->create(['grade' => 85.5, 'result' => KardexResult::Aprobada, 'attempt' => 2]);

    expect($record->fresh())
        ->result->toBe(KardexResult::Aprobada)
        ->grade->toBe('85.50')
        ->attempt->toBe(2)
        ->and($record->student)->toBeInstanceOf(Student::class)
        ->and($record->subject)->toBeInstanceOf(Subject::class);
});

it('el progreso guarda las listas de materias como arreglos de ids', function () {
    [$pending, $available] = Subject::factory()->count(2)->create()->modelKeys();
    $progress = StudentProgress::factory()->create([
        'subjects_pending' => [$pending, $available],
        'subjects_available' => [$available],
    ]);

    expect($progress->fresh())
        ->subjects_pending->toBe([$pending, $available])
        ->subjects_available->toBe([$available])
        ->and($progress->studyPlan->id)->toBe($progress->study_plan_id);
});
