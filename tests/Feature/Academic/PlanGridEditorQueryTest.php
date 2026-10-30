<?php

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Specialty;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Queries\PlanGridEditorQuery;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;

/*
| La retícula de un plan para su editor (academic::pages.plan): sus materias por semestre, con su lugar, su
| seriación (lo que pide y lo que la pide) y su cadena; sus especialidades con cuántas materias tienen; y hasta
| qué semestre llega. Sin lo que se quitó de la retícula ni las materias borradas del catálogo, en un número fijo
| de consultas.
*/

beforeEach(function () {
    $this->plan = StudyPlan::factory()->create(['code' => 'ISIC-2010-224', 'semesters_count' => 4]);
    $this->department = Department::factory()->create(['name' => 'Sistemas y Computación']);
    $this->subject = fn (string $code, array $attributes = []): Subject => Subject::factory()->for($this->department)->create([
        'code' => $code, 'name' => "Materia {$code}", 'theory_hours' => 3, 'practice_hours' => 2, 'credits' => 5, ...$attributes,
    ]);
});

function editorGrid(StudyPlan $plan): array
{
    return app(PlanGridEditorQuery::class)->forPlan($plan);
}

/**
 * @return list<string>
 */
function codesIn(array $semester): array
{
    return array_column($semester, 'code');
}

it('agrupa por semestre e incluye los semestres vacíos hasta los del plan', function () {
    $calculus = placeInPlan($this->plan, ($this->subject)('ACF-0901'));
    placeInPlan($this->plan, ($this->subject)('ACA-0907'));
    placeInPlan($this->plan, ($this->subject)('ACF-0904', ['is_active' => false]), 3);

    $grid = editorGrid($this->plan);

    expect($grid['semesters_count'])->toBe(4)
        ->and(array_keys($grid['semesters']))->toBe([1, 2, 3, 4])
        ->and(codesIn($grid['semesters'][1]))->toBe(['ACA-0907', 'ACF-0901'])
        ->and($grid['semesters'][2])->toBe([])
        ->and(codesIn($grid['semesters'][3]))->toBe(['ACF-0904'])
        ->and($grid['semesters'][4])->toBe([])
        ->and($grid['semesters'][1][1])->toBe([
            'placement_id' => $calculus->id,
            'subject_id' => $calculus->subject_id,
            'code' => 'ACF-0901',
            'name' => 'Materia ACF-0901',
            'credits' => 5,
            'theory_hours' => 3,
            'practice_hours' => 2,
            'department' => 'Sistemas y Computación',
            'semester' => 1,
            'specialty_id' => null,
            'is_required' => true,
            'is_active' => true,
            'chain' => null,
            'requires' => [],
            'required_by' => [],
        ])
        ->and($grid['semesters'][3][0]['is_active'])->toBeFalse();
});

it('sin semestres capturados, o con materias más allá de ellos, llega hasta el mayor semestre con materias', function () {
    $this->plan->update(['semesters_count' => null]);
    placeInPlan($this->plan, ($this->subject)('ACF-0901'), 2);

    expect(editorGrid($this->plan))->semesters_count->toBe(2)
        ->and(array_keys(editorGrid($this->plan)['semesters']))->toBe([1, 2]);

    $this->plan->update(['semesters_count' => 1]);
    placeInPlan($this->plan, ($this->subject)('RES-1000'), 3);

    expect(editorGrid($this->plan))->semesters_count->toBe(3);
});

it('un plan sin semestres ni materias no tiene semestres', function () {
    $this->plan->update(['semesters_count' => null]);

    expect(editorGrid($this->plan))->toBe(['semesters' => [], 'specialties' => [], 'chains' => [], 'semesters_count' => 0]);
});

it('no trae filas quitadas de la retícula, materias borradas del catálogo ni su seriación', function () {
    $kept = ($this->subject)('ACF-0901');
    $removed = ($this->subject)('ACF-0902');
    $deleted = ($this->subject)('ACF-0903');
    $other = ($this->subject)('ACF-0904');
    placeInPlan($this->plan, $kept);
    placeInPlan($this->plan, $removed, 2);
    placeInPlan($this->plan, $deleted, 2);
    placeInPlan($this->plan, $other, 3);
    seriate($this->plan, $removed, $kept);
    seriate($this->plan, $deleted, $kept);
    seriate($this->plan, $other, $kept)->delete();

    $this->plan->placements()->where('subject_id', $removed->id)->sole()->delete();
    $deleted->delete();
    placeInPlan(StudyPlan::factory()->create(), ($this->subject)('OTRO-0001'));

    $grid = editorGrid($this->plan);

    expect(array_merge(...array_map('codesIn', $grid['semesters'])))->toBe(['ACF-0901', 'ACF-0904'])
        ->and($grid['semesters'][1][0]['required_by'])->toBe([])
        ->and($grid['semesters'][3][0]['requires'])->toBe([]);
});

it('dice qué pide cada materia y quién la pide, con su tipo', function () {
    $calculus = ($this->subject)('ACF-0901', ['name' => 'Cálculo Diferencial']);
    $integral = ($this->subject)('ACF-0902', ['name' => 'Cálculo Integral']);
    $numerical = ($this->subject)('SCC-1017', ['name' => 'Métodos Numéricos']);
    placeInPlan($this->plan, $calculus);
    placeInPlan($this->plan, $integral, 2);
    placeInPlan($this->plan, $numerical, 2);
    $seriation = seriate($this->plan, $integral, $calculus);
    $corequisite = seriate($this->plan, $integral, $numerical, PrerequisiteKind::Correquisito);

    [$integralRow, $numericalRow] = editorGrid($this->plan)['semesters'][2];

    expect($integralRow['requires'])->toBe([
        ['prerequisite_id' => $seriation->id, 'subject_id' => $calculus->id, 'code' => 'ACF-0901', 'name' => 'Cálculo Diferencial', 'semester' => 1, 'kind' => 'seriacion'],
        ['prerequisite_id' => $corequisite->id, 'subject_id' => $numerical->id, 'code' => 'SCC-1017', 'name' => 'Métodos Numéricos', 'semester' => 2, 'kind' => 'correquisito'],
    ])
        ->and($numericalRow['required_by'])->toBe([
            ['prerequisite_id' => $corequisite->id, 'subject_id' => $integral->id, 'code' => 'ACF-0902', 'name' => 'Cálculo Integral', 'semester' => 2, 'kind' => 'correquisito'],
        ])
        ->and(editorGrid($this->plan)['semesters'][1][0]['required_by'][0]['code'])->toBe('ACF-0902');
});

it('pone el tronco común antes que las especialidades en cada semestre, y cuenta las materias de cada especialidad', function () {
    $software = Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software']);
    $networks = Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-RCS-2020', 'name' => 'Redes', 'is_active' => false]);
    $empty = Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-CDA-2022', 'name' => 'Ciencia de Datos']);
    Specialty::factory()->for($this->plan)->create(['code' => 'ISIC-RYT-2014'])->delete();
    Specialty::factory()->create(['code' => 'OTRO-PLAN']);

    placeInPlan($this->plan, ($this->subject)('RSD-2001'), 4, $networks);
    placeInPlan($this->plan, ($this->subject)('ISD-2001'), 4, $software);
    placeInPlan($this->plan, ($this->subject)('SCC-1012'), 4);
    placeInPlan($this->plan, ($this->subject)('ISD-2002'), 4, $software);
    placeInPlan($this->plan, ($this->subject)('ISD-2003'), 3, $software)->delete();

    $grid = editorGrid($this->plan);

    expect(codesIn($grid['semesters'][4]))->toBe(['SCC-1012', 'ISD-2001', 'ISD-2002', 'RSD-2001'])
        ->and($grid['semesters'][4][1]['specialty_id'])->toBe($software->id)
        ->and($grid['specialties'])->toBe([
            ['id' => $empty->id, 'code' => 'ISIC-CDA-2022', 'name' => 'Ciencia de Datos', 'is_active' => true, 'subjects' => 0],
            ['id' => $software->id, 'code' => 'ISIC-ISW-2020', 'name' => 'Ingeniería de Software', 'is_active' => true, 'subjects' => 2],
            ['id' => $networks->id, 'code' => 'ISIC-RCS-2020', 'name' => 'Redes', 'is_active' => false, 'subjects' => 1],
        ]);
});

it('arma las cadenas de seriación sin los correquisitos, en el orden de la retícula y nombradas por su primera materia', function () {
    $s = [];
    foreach (['ACF-0901' => 1, 'ACF-0902' => 2, 'ACF-0904' => 3, 'AED-1285' => 1, 'AED-1286' => 2, 'SCC-1017' => 2, 'ACA-0907' => 1] as $code => $semester) {
        $s[$code] = ($this->subject)($code, ['name' => "Materia {$code}"]);
        placeInPlan($this->plan, $s[$code], $semester);
    }
    seriate($this->plan, $s['ACF-0902'], $s['ACF-0901']);
    seriate($this->plan, $s['ACF-0904'], $s['ACF-0902']);
    seriate($this->plan, $s['AED-1286'], $s['AED-1285']);
    seriate($this->plan, $s['SCC-1017'], $s['ACA-0907'], PrerequisiteKind::Correquisito);

    $grid = editorGrid($this->plan);
    $chainOf = collect($grid['semesters'])->flatten(1)->pluck('chain', 'code')->all();

    expect($grid['chains'])->toBe([
        ['index' => 0, 'name' => 'Materia ACF-0901', 'size' => 3],
        ['index' => 1, 'name' => 'Materia AED-1285', 'size' => 2],
    ])
        ->and($chainOf)->toBe([
            'ACA-0907' => null, 'ACF-0901' => 0, 'AED-1285' => 1,
            'ACF-0902' => 0, 'AED-1286' => 1, 'SCC-1017' => null,
            'ACF-0904' => 0,
        ]);
});

it('lee una retícula de 60 materias con su seriación y especialidades en 3 consultas', function () {
    $specialties = Specialty::factory()->for($this->plan)->count(3)->create();
    $previous = null;

    foreach (range(1, 60) as $i) {
        $subject = ($this->subject)(sprintf('MAT-%04d', $i));
        placeInPlan($this->plan, $subject, intdiv($i - 1, 7) + 1, $i > 50 ? $specialties[$i % 3] : null);

        if ($previous !== null && $i % 3 !== 0) {
            seriate($this->plan, $subject, $previous, $i % 5 === 0 ? PrerequisiteKind::Correquisito : PrerequisiteKind::Seriacion);
        }

        $previous = $subject;
    }

    $plan = $this->plan->fresh();
    DB::enableQueryLog();
    $grid = editorGrid($plan);
    $queries = count(DB::getQueryLog());

    expect($queries)->toBe(3)
        ->and(collect($grid['semesters'])->flatten(1))->toHaveCount(60)
        ->and($grid['semesters_count'])->toBe(9);
});

describe('búsquedas por id', function () {
    it('encuentra una fila de la retícula o una especialidad solo dentro del plan', function () {
        $placement = placeInPlan($this->plan, ($this->subject)('ACF-0901'));
        $foreign = placeInPlan(StudyPlan::factory()->create(), ($this->subject)('ACF-0902'));
        $specialty = Specialty::factory()->for($this->plan)->create();
        $foreignSpecialty = Specialty::factory()->create();
        $query = app(PlanGridEditorQuery::class);

        expect($query->placementOrFail($this->plan, $placement->id)->is($placement))->toBeTrue()
            ->and($query->specialtyOrFail($this->plan, $specialty->id)->is($specialty))->toBeTrue()
            ->and(fn () => $query->placementOrFail($this->plan, $foreign->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->specialtyOrFail($this->plan, $foreignSpecialty->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->placementOrFail($this->plan, 'no-es-uuid'))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->specialtyOrFail($this->plan, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);
    });

    it('no encuentra una fila quitada, una materia borrada del catálogo ni una especialidad quitada', function () {
        $removed = placeInPlan($this->plan, ($this->subject)('ACF-0901'));
        $removed->delete();
        $deletedSubject = ($this->subject)('ACF-0902');
        $orphan = placeInPlan($this->plan, $deletedSubject);
        $deletedSubject->delete();
        $specialty = Specialty::factory()->for($this->plan)->create();
        $specialty->delete();
        $query = app(PlanGridEditorQuery::class);

        expect(fn () => $query->placementOrFail($this->plan, $removed->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->placementOrFail($this->plan, $orphan->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->specialtyOrFail($this->plan, $specialty->id))->toThrow(ModelNotFoundException::class);
    });

    it('encuentra un requisito sin quitar solo dentro del plan; uno quitado, de otro plan o que no es uuid no; y sabe si uno del plan se quitó', function () {
        $calculus = ($this->subject)('ACF-0901');
        $integral = ($this->subject)('ACF-0902');
        placeInPlan($this->plan, $calculus);
        placeInPlan($this->plan, $integral, 2);
        $prerequisite = seriate($this->plan, $integral, $calculus);
        $removed = seriate($this->plan, $calculus, $integral, PrerequisiteKind::Correquisito);
        $removed->delete();
        $other = StudyPlan::factory()->create();
        placeInPlan($other, $calculus);
        placeInPlan($other, $integral, 2);
        $foreign = seriate($other, $integral, $calculus);
        $query = app(PlanGridEditorQuery::class);

        expect($query->prerequisiteOrFail($this->plan, $prerequisite->id)->is($prerequisite))->toBeTrue()
            ->and(fn () => $query->prerequisiteOrFail($this->plan, $removed->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->prerequisiteOrFail($this->plan, $foreign->id))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->prerequisiteOrFail($this->plan, 'no-es-uuid'))->toThrow(ModelNotFoundException::class);

        $foreign->delete();

        expect($query->prerequisiteWasRemoved($this->plan, $removed->id))->toBeTrue()
            ->and($query->prerequisiteWasRemoved($this->plan, $prerequisite->id))->toBeFalse()
            ->and($query->prerequisiteWasRemoved($this->plan, $foreign->id))->toBeFalse()
            ->and($query->prerequisiteWasRemoved($this->plan, fakeKey(99)))->toBeFalse()
            ->and($query->prerequisiteWasRemoved($this->plan, 'no-es-uuid'))->toBeFalse();
    });
});

describe('catálogo para «Agregar materia»', function () {
    it('busca con todas las palabras en la clave o el nombre, primero las que se pueden poner, y dice por qué no las demás', function () {
        $free = ($this->subject)('SCD-1027', ['name' => 'Tópicos de Bases de Datos']);
        $placed = ($this->subject)('AEF-1031', ['name' => 'Fundamentos de Base de Datos']);
        placeInPlan($this->plan, $placed, 4);
        $removed = ($this->subject)('BDA-2101', ['name' => 'Bases de Datos Avanzadas']);
        placeInPlan($this->plan, $removed, 2)->delete();
        $elsewhere = ($this->subject)('TIB-1009', ['name' => 'Bases de Datos en la Nube']);
        placeInPlan(StudyPlan::factory()->create(), $elsewhere, 3);
        ($this->subject)('IFD-1005', ['name' => 'Bases de Datos I', 'is_active' => false]);
        ($this->subject)('TIB-1008', ['name' => 'Bases de Datos Distribuidas'])->delete();
        ($this->subject)('ACF-0901', ['name' => 'Cálculo Diferencial']);

        $catalog = app(PlanGridEditorQuery::class)->catalog($this->plan, 'datos BASE');

        expect($catalog)->total->toBe(6)->searched->toBeTrue()->matches->toBe(6)
            ->and(array_map(fn (array $row): string => $row['code'].($row['placeable'] ? '' : ' no'), $catalog['subjects']))
            ->toBe(['BDA-2101', 'SCD-1027', 'TIB-1009', 'AEF-1031 no', 'IFD-1005 no', 'TIB-1008 no'])
            ->and($catalog['subjects'][3])->toBe([
                'id' => $placed->id,
                'code' => 'AEF-1031',
                'name' => 'Fundamentos de Base de Datos',
                'theory_hours' => 3,
                'practice_hours' => 2,
                'credits' => 5,
                'department' => 'Sistemas y Computación',
                'is_active' => true,
                'deleted' => false,
                'semester' => 4,
                'placeable' => false,
            ])
            ->and($catalog['subjects'][0])->semester->toBeNull()->placeable->toBeTrue()
            ->and($catalog['subjects'][4])->is_active->toBeFalse()->deleted->toBeFalse()
            ->and($catalog['subjects'][5])->deleted->toBeTrue();
    });

    it('no busca con menos de dos letras, y muestra a lo más 8 con cuántas coinciden', function () {
        foreach (range(1, 12) as $i) {
            ($this->subject)(sprintf('OPT-%04d', $i), ['name' => "Optativa {$i}"]);
        }

        $query = app(PlanGridEditorQuery::class);

        expect($query->catalog($this->plan, ' o '))->toBe(['total' => 12, 'searched' => false, 'matches' => 0, 'subjects' => []])
            ->and($query->catalog($this->plan, 'optativa'))->matches->toBe(12)->subjects->toHaveCount(8)
            ->and($query->catalog($this->plan, '50%'))->matches->toBe(0)->subjects->toBe([]);
    });

    it('lee el catálogo en 2 consultas', function () {
        foreach (range(1, 30) as $i) {
            placeInPlan($this->plan, ($this->subject)(sprintf('OPT-%04d', $i)), $i % 4 + 1);
        }

        DB::enableQueryLog();
        app(PlanGridEditorQuery::class)->catalog($this->plan, 'opt');

        expect(DB::getQueryLog())->toHaveCount(2);
    });

    it('encuentra una materia del catálogo para ponerla, también borrada; una que no existe o no es uuid no', function () {
        $deleted = ($this->subject)('TIB-1008');
        $deleted->delete();
        $query = app(PlanGridEditorQuery::class);

        expect($query->subjectOrFail($deleted->id)->is($deleted))->toBeTrue()
            ->and(fn () => $query->subjectOrFail(fakeKey(99)))->toThrow(ModelNotFoundException::class)
            ->and(fn () => $query->subjectOrFail('no-es-uuid'))->toThrow(ModelNotFoundException::class);
    });
});
