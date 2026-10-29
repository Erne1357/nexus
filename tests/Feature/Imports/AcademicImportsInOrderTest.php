<?php

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\Actions\ApplyImportBatch;
use App\Modules\Imports\Actions\ValidateImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\EligibleStudentsQuery;
use Illuminate\Support\Facades\Storage;

/*
| Importar de punta a punta en el orden de reglas-de-negocio.md, "Reglas de datos" (tarea 1.9): departamentos,
| carreras, planes, materias por plan, seriación, alumnos y kardex, cada uno con su archivo, validado y aplicado
| como lo hace el asistente. Al final, el avance de los alumnos sale de lo importado y cuenta en los elegibles.
*/

beforeEach(function () {
    Storage::fake('imports');
    $this->campus = Campus::factory()->create(['code' => 'C2']);
    $this->admin = User::factory()->admin()->create();
});

/**
 * Valida y aplica un archivo CSV con la plantilla dada, como el asistente de importación.
 *
 * @param  list<string>  $lines
 */
function importInOrder(string $template, array $lines): ImportBatch
{
    $admin = test()->admin;
    $batch = ImportBatch::factory()->create(['template' => $template, 'uploaded_by' => $admin->id]);
    $batch->update(['file_path' => "{$batch->id}.csv"]);
    Storage::disk('imports')->put($batch->file_path, implode("\n", $lines));

    app(ValidateImportBatch::class)->handle($batch);

    return app(ApplyImportBatch::class)->handle($batch->fresh(), $admin);
}

it('importa el catálogo académico, los alumnos y el kardex en orden, y el alumno queda elegible para lo que abre su seriación', function () {
    $batches = [
        importInOrder('departments', ['clave,nombre', 'SC,Sistemas y Computación']),
        importInOrder('careers', ['clave,nombre,departamento,campus', 'ISIC,Ingeniería en Sistemas Computacionales,SC,C2']),
        importInOrder('study_plans', ['clave de plan,carrera,versión,vigente', 'ISIC-2010-224,ISIC,2010,Sí']),
        importInOrder('subjects', [
            'clave de plan,clave de materia,nombre,semestre,horas teoría,horas práctica,créditos,departamento',
            'ISIC-2010-224,ACF-0901,Cálculo Diferencial,1,3,2,5,SC',
            'ISIC-2010-224,ACF-0902,Cálculo Integral,2,3,2,5,SC',
            'ISIC-2010-224,ACF-0904,Cálculo Vectorial,3,3,2,5,SC',
        ]),
        importInOrder('prerequisites', ['clave de plan,materia,materia requisito', 'ISIC-2010-224,ACF-0902,ACF-0901', 'ISIC-2010-224,ACF-0904,ACF-0902']),
        importInOrder('students', ['número de control,nombre,carrera,plan,campus,semestre,estatus', '24111234,Daniela Ortiz,ISIC,ISIC-2010-224,C2,1,Activo']),
        importInOrder('kardex', ['número de control,clave de materia,periodo,calificación,resultado,oportunidad', '24111234,ACF-0901,20253,85,Aprobada,1']),
    ];

    $student = Student::where('control_number', '24111234')->sole();
    $counts = app(EligibleStudentsQuery::class)->countsFor(Career::where('code', 'ISIC')->sole(), $this->campus);

    expect(collect($batches)->map(fn (ImportBatch $batch): array => [$batch->template, $batch->status, $batch->error_rows])->all())
        ->each->toMatchArray([1 => BatchStatus::Applied, 2 => 0])
        ->and($student->progress)->credits_approved->toBe(5)
        ->subjects_available->toBe([Subject::where('code', 'ACF-0902')->value('id')])
        ->and($counts)->toBe([Subject::where('code', 'ACF-0902')->value('id') => 1]);
});
