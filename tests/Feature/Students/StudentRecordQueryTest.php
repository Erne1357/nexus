<?php

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Students\Actions\RefreshStudentProgress;
use App\Modules\Students\Enums\SubjectStanding;
use App\Modules\Students\Models\KardexRecord;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Queries\GridCell;
use App\Modules\Students\Queries\StudentRecord;
use App\Modules\Students\Queries\StudentRecordQuery;

/*
| StudentRecordQuery arma la ficha del alumno (tarea 1.6): su retícula por semestre con el estado de cada materia
| como lo nombra el SII, las cadenas de seriación, sus cifras y su kárdex por periodo. El avance se calcula al
| leer, desde su kardex, para que la ficha no espere al siguiente recálculo (reglas-de-negocio.md, "Retícula del
| alumno").
*/

beforeEach(function () {
    // Primero: Cálculo (A), Física (F) y Química (Q); segundo: Cálculo II (B, pide A), Dinámica (H, pide F),
    // Dibujo (C) y Taller (D); cuarto: Circuitos (G).
    $this->plan = StudyPlan::factory()->create(['total_credits' => 100]);
    $this->subjects = [];
    foreach (['A' => 1, 'F' => 1, 'Q' => 1, 'B' => 2, 'H' => 2, 'C' => 2, 'D' => 2, 'G' => 4] as $code => $semester) {
        $this->subjects[$code] = Subject::factory()->create(['code' => "{$code}-{$semester}", 'credits' => 5]);
        placeInPlan($this->plan, $this->subjects[$code], $semester);
    }
    seriate($this->plan, $this->subjects['B'], $this->subjects['A']);
    seriate($this->plan, $this->subjects['H'], $this->subjects['F']);
    $this->student = Student::factory()->for($this->plan)->create(['current_semester' => 1]);
});

function recordKardex(Student $student, Subject $subject, string $result, string $term, int $attempt = 1, ?float $grade = null): KardexRecord
{
    return KardexRecord::factory()->for($student)->for($subject)->create(['result' => $result, 'term_code' => $term, 'attempt' => $attempt, 'grade' => $grade]);
}

function recordOf(Student $student): StudentRecord
{
    return app(StudentRecordQuery::class)->forStudent($student);
}

function cellOf(StudentRecord $record, string $code): GridCell
{
    foreach ($record->semesters as $cells) {
        foreach ($cells as $cell) {
            if (str_starts_with($cell->code, "{$code}-")) {
                return $cell;
            }
        }
    }

    throw new RuntimeException("La materia {$code} no está en la retícula.");
}

it('da el estado de cada materia como el SII, desde su kardex', function () {
    recordKardex($this->student, $this->subjects['A'], 'aprobada', '20253', grade: 85);
    recordKardex($this->student, $this->subjects['F'], 'reprobada', '20253', grade: 40);
    recordKardex($this->student, $this->subjects['Q'], 'reprobada', '20251', grade: 50);
    recordKardex($this->student, $this->subjects['Q'], 'reprobada', '20253', 2, 55);
    recordKardex($this->student, $this->subjects['C'], 'cursando', '20261');
    recordKardex($this->student, $this->subjects['D'], 'cursando', '20253');

    $record = recordOf($this->student);

    expect(array_keys($record->semesters))->toBe([1, 2, 4])
        ->and(cellOf($record, 'A'))->standing->toBe(SubjectStanding::Acreditada)->grade->toBe('85')->termCode->toBe('20253')
        ->and(cellOf($record, 'F')->standing)->toBe(SubjectStanding::ReprobadaPrioridad)
        ->and(cellOf($record, 'Q')->standing)->toBe(SubjectStanding::ACursoEspecial)
        ->and(cellOf($record, 'C')->standing)->toBe(SubjectStanding::Cursando)
        ->and(cellOf($record, 'D')->standing)->toBe(SubjectStanding::SinCalificar)
        ->and(cellOf($record, 'B')->standing)->toBe(SubjectStanding::PuedeSeleccionarla)
        ->and(cellOf($record, 'H'))->standing->toBe(SubjectStanding::NoPermitida)->missing->toBe(['F-1'])
        ->and(cellOf($record, 'G'))->standing->toBe(SubjectStanding::NoPermitida)->beyondReach->toBeTrue();
});

it('el periodo en curso es el más reciente del kardex de su campus, sin contar verano', function () {
    $neighbor = Student::factory()->for($this->plan)->create(['campus_id' => $this->student->campus_id]);
    $elsewhere = Student::factory()->for($this->plan)->create(['campus_id' => Campus::factory()->create()->id]);
    recordKardex($this->student, $this->subjects['C'], 'cursando', '20261');
    recordKardex($neighbor, $this->subjects['A'], 'cursando', '20262');
    recordKardex($elsewhere, $this->subjects['A'], 'cursando', '20263');

    expect(cellOf(recordOf($this->student), 'C')->standing)->toBe(SubjectStanding::Cursando);
});

it('guarda el periodo en curso hasta el siguiente recálculo del avance, que corre con cada importación', function () {
    recordKardex($this->student, $this->subjects['C'], 'cursando', '20261');
    recordOf($this->student);
    $neighbor = Student::factory()->for($this->plan)->create(['campus_id' => $this->student->campus_id]);
    recordKardex($neighbor, $this->subjects['A'], 'cursando', '20263');

    expect(cellOf(recordOf($this->student), 'C')->standing)->toBe(SubjectStanding::Cursando);

    app(RefreshStudentProgress::class)->handle();

    expect(cellOf(recordOf($this->student), 'C')->standing)->toBe(SubjectStanding::SinCalificar);
});

it('una materia reprobada muestra su última oportunidad, y la del curso especial ya no cuenta para seleccionar', function () {
    recordKardex($this->student, $this->subjects['Q'], 'reprobada', '20253', 3, 40);

    $record = recordOf($this->student);

    expect(cellOf($record, 'Q'))->standing->toBe(SubjectStanding::CursoEspecialReprobado)->attempt->toBe(3)->grade->toBe('40')->termCode->toBe('20253')
        ->and($record->selectable)->toBe(4);
});

it('dice el porcentaje de créditos que pide una materia que todavía no puede cursar', function () {
    $residency = Subject::factory()->create(['code' => 'R-2', 'kind' => SubjectKind::Residencia]);
    placeInPlan($this->plan, $residency, 2);

    expect(cellOf(recordOf($this->student), 'R'))->standing->toBe(SubjectStanding::NoPermitida)->creditShare->toBe(80);
});

it('una vez elegida la especialidad, ya no muestra las materias de las otras', function () {
    $networks = Specialty::factory()->for($this->plan)->create();
    $software = Specialty::factory()->for($this->plan)->create(['name' => 'Software']);
    $r = Subject::factory()->create(['code' => 'R-2']);
    $s = Subject::factory()->create(['code' => 'S-2']);
    placeInPlan($this->plan, $r, 2, $networks);
    placeInPlan($this->plan, $s, 2, $software);

    expect(cellOf(recordOf($this->student), 'R'))->not->toBeNull()
        ->and(cellOf(recordOf($this->student), 'S')->specialtyId)->toBe($software->id);

    recordKardex($this->student, $s, 'cursando', '20261');

    expect(fn () => cellOf(recordOf($this->student), 'R'))->toThrow(RuntimeException::class)
        ->and(recordOf($this->student)->specialties)->toBe([['id' => $software->id, 'name' => 'Software', 'subjects' => 1]]);
});

it('en cada semestre va primero el tronco común y luego cada especialidad, por su clave, con sus materias', function () {
    $networks = Specialty::factory()->for($this->plan)->create(['code' => 'ESP-B', 'name' => 'Redes']);
    $software = Specialty::factory()->for($this->plan)->create(['code' => 'ESP-A', 'name' => 'Software']);
    placeInPlan($this->plan, Subject::factory()->create(['code' => '0-2']), 2, $networks);
    placeInPlan($this->plan, Subject::factory()->create(['code' => '1-2']), 2, $software);
    placeInPlan($this->plan, Subject::factory()->create(['code' => '2-4']), 4, $networks);

    $record = recordOf($this->student);

    expect(array_map(fn (GridCell $cell): string => $cell->code, $record->semesters[2]))->toBe(['B-2', 'C-2', 'D-2', 'H-2', '1-2', '0-2'])
        ->and(array_map(fn (GridCell $cell): ?string => $cell->specialtyId, $record->semesters[4]))->toBe([null, $networks->id])
        ->and($record->specialties)->toBe([
            ['id' => $software->id, 'name' => 'Software', 'subjects' => 1],
            ['id' => $networks->id, 'name' => 'Redes', 'subjects' => 2],
        ]);
});

it('agrupa las materias en cadenas de seriación, nombradas por la primera', function () {
    $record = recordOf($this->student);

    expect($record->chains)->toBe([
        ['index' => 0, 'name' => $this->subjects['A']->name, 'size' => 2],
        ['index' => 1, 'name' => $this->subjects['F']->name, 'size' => 2],
    ])
        ->and(cellOf($record, 'A')->chain)->toBe(0)
        ->and(cellOf($record, 'B'))->chain->toBe(0)->prerequisites->toBe(['A-1'])
        ->and(cellOf($record, 'H')->chain)->toBe(1)
        ->and(cellOf($record, 'Q')->chain)->toBeNull();
});

it('resume los créditos, las materias acreditadas, lo que cursa y lo que puede seleccionar', function () {
    recordKardex($this->student, $this->subjects['A'], 'aprobada', '20253', grade: 90);
    recordKardex($this->student, $this->subjects['F'], 'cursando', '20261');

    expect(recordOf($this->student))
        ->creditsApproved->toBe(5)
        ->totalCredits->toBe(100)
        ->subjectsApproved->toBe(1)
        ->subjectsInGrid->toBe(8)
        ->taking->toBe(1)
        ->selectable->toBe(4)
        ->reachSemester->toBe(3)
        ->approvedBySemester->toBe([1 => 1, 2 => 0, 4 => 0]);
});

it('el kárdex va por periodo, del más antiguo al más reciente, también con materias de fuera del plan y sin las filas borradas', function () {
    $outside = Subject::factory()->create(['code' => 'Z-9']);
    recordKardex($this->student, $this->subjects['A'], 'reprobada', '20251', grade: 50);
    recordKardex($this->student, $this->subjects['A'], 'aprobada', '20253', 2, 80);
    recordKardex($this->student, $outside, 'aprobada', '20253', grade: 95);
    recordKardex($this->student, $this->subjects['F'], 'aprobada', '20243', grade: 70)->delete();

    $kardex = recordOf($this->student)->kardex;

    expect(array_column($kardex, 'term'))->toBe(['20251', '20253'])
        ->and(array_column($kardex, 'label'))->toBe(['Ene-Jun 2025', 'Ago-Dic 2025'])
        ->and(collect($kardex[1]['records'])->map(fn (KardexRecord $row): string => "{$row->subject->code} {$row->attempt}")->all())->toBe(['A-1 2', 'Z-9 1']);
});

it('muestra la calificación sin ceros de más, sin redondearla, y la oportunidad con su nombre', function () {
    $passed = recordKardex($this->student, $this->subjects['A'], 'aprobada', '20253', 2, 69.6);
    $first = recordKardex($this->student, $this->subjects['F'], 'aprobada', '20253', grade: 88);
    $special = recordKardex($this->student, $this->subjects['Q'], 'reprobada', '20253', 3, 40);

    expect(cellOf(recordOf($this->student), 'A')->grade)->toBe('69.6')
        ->and($passed->fresh())->grade_label->toBe('69.6')->attempt_label->toBe('2 · repetición')
        ->and($first->fresh())->grade_label->toBe('88')->attempt_label->toBe('1 · ordinaria')
        ->and($special->fresh()->attempt_label)->toBe('3 · curso especial');
});
