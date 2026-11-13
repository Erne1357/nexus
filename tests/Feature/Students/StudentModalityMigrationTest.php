<?php

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Students\Models\Student;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/*
| La migración que cambia la modalidad del alumno de texto a la llave del catálogo (MS7, supuesto por confirmar):
| presencial pasa a `PRES`; a distancia y mixta, a `EAD`; sin modalidad, a `PRES`. Se prueba sobre la forma vieja:
| `down()`, filas con `DB::table()` y `up()`.
*/

beforeEach(function () {
    $this->migration = require base_path('app/Modules/Students/Database/Migrations/2026_11_12_210101_replace_student_modality_with_modality_id.php');
    $this->migration->down();
    $this->plan = StudyPlan::factory()->create();
    $this->campus = Campus::factory()->create();
});

/**
 * Inserta un alumno con la forma de antes del catálogo (modalidad como texto) y devuelve su id.
 */
function studentBeforeModalities(object $test, string $number, ?string $modality): string
{
    $id = (string) Str::uuid7();

    DB::table('students')->insert([
        'id' => $id,
        'control_number' => $number,
        'name' => "Alumno {$number}",
        'career_id' => $test->plan->career_id,
        'study_plan_id' => $test->plan->id,
        'campus_id' => $test->campus->id,
        'current_semester' => 1,
        'is_active' => true,
        'modality' => $modality,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

it('MS7: presencial, a distancia, mixta y sin modalidad pasan a PRES, EAD, EAD y PRES, y el texto desaparece', function () {
    $presencial = studentBeforeModalities($this, '1', 'presencial');
    $distancia = studentBeforeModalities($this, '2', 'distancia');
    $mixta = studentBeforeModalities($this, '3', 'mixta');
    $nula = studentBeforeModalities($this, '4', null);

    $this->migration->up();

    $code = fn (string $id): string => Student::query()->findOrFail($id)->modality->code;

    expect($code($presencial))->toBe('PRES')
        ->and($code($distancia))->toBe('EAD')
        ->and($code($mixta))->toBe('EAD')
        ->and($code($nula))->toBe('PRES')
        ->and(Schema::hasColumn('students', 'modality'))->toBeFalse()
        ->and(Student::query()->first()->modality)->toBeInstanceOf(Modality::class);
});

it('la modalidad del alumno es obligatoria', function () {
    $this->migration->up();

    expect(fn () => DB::table('students')->insert([
        'id' => (string) Str::uuid7(),
        'control_number' => '9',
        'name' => 'Sin modalidad',
        'career_id' => $this->plan->career_id,
        'study_plan_id' => $this->plan->id,
        'campus_id' => $this->campus->id,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]))->toThrow(QueryException::class);
});
