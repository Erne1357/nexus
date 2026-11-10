<?php

use App\Modules\Academic\Actions\ResolvePendingPlacement;
use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanPrerequisitePending;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Academic\Models\Subject;
use App\Modules\Academic\Models\SubjectPrerequisite;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\User;
use App\Modules\Imports\ImportsServiceProvider;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Sii\RunSiiMigration;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use App\Modules\Imports\Sii\Steps\SeriationStep;
use App\Modules\Imports\Sii\Steps\SubjectsStep;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Unit\Imports\Sii\SiiFixtures;

/*
| Paso `seriacion` de imports:migrate-sii (decisión del usuario del 10 de noviembre de 2026, confirmada): cada renglón de
| `requisitos_materia` es un requisito de una materia en una retícula del SII, P (pre-requisito: seriación) o C
| (co-requisito: correquisito). Va al plan de Nexus de su retícula con las materias de ese plan (`subject_for_plan`):
| si las dos están en la retícula, a la seriación (`prerequisites`); si alguna está en «Por validar», a los requisitos
| que esperan (`pending_prerequisites`), que entran solos cuando Servicios Escolares le asigna semestre. Datos
| sintéticos: materias, retículas y claves inventadas.
*/

const SERIATION_STEP_PLAN_FOR = [
    'A/1' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'presencial'],
    'A2/2' => ['plan' => 'AAA-2010-224', 'career' => 'AAA', 'modality' => 'distancia'],
    'A/3' => ['plan' => 'AAA-2004-100', 'career' => 'AAA', 'modality' => 'presencial'],
];

const SERIATION_STEP_SUBJECTS = [
    'AAA-2010-224' => [
        'CD1' => 'ACF-0901', 'CD1D' => 'ACF-0901', 'CD2' => 'ACF-0902', 'QU1' => 'AEF-1001', 101 => 'AEF-1001',
        'ZZ1' => 'ZZZ1', 'ZZ2' => 'ZZZ2', 'FAN' => 'FANTASMA',
    ],
    'AAA-2004-100' => ['CD1' => 'OLD1', 'CD2' => 'OLD2'],
];

/**
 * Escribe `requisitos_materia.csv` con las claves rellenas de espacios, como las exporta el SII.
 *
 * @param  list<array{string, string, string, string, string}>  $rows  carrera, retícula, materia, materia requisito y tipo
 */
function writeSiiRequisites(string $dir, array $rows): void
{
    $text = fn (string $value): string => "'".str_pad($value, 10)."'";

    SiiFixtures::write($dir.'/requisitos_materia.csv', SiiFile::RequisitosMateria, array_map(fn (array $r): string => SiiFixtures::line(SiiFile::RequisitosMateria, [
        'carrera' => $text($r[0]), 'reticula' => $r[1], 'materia' => $text($r[2]), 'materia_relacion' => $text($r[3]), 'tipo_requisito' => "'{$r[4]}'",
    ]), $rows));
}

/**
 * Corre el paso con los mapas de los pasos anteriores armados a mano.
 *
 * @param  array<string, array<string|int, string>>  $subjectForPlan
 * @return array<string, array{file: string, rows: list<array<string, scalar|null>>, incidents: list<array{level: string, ref: string, message: string}>}>
 */
function seriationStepRun(string $dir, array $subjectForPlan = SERIATION_STEP_SUBJECTS): array
{
    $migration = new SiiMigration(SiiExport::open($dir), '/nonexistent');
    $migration->remember('plan_for', fn (): array => SERIATION_STEP_PLAN_FOR);
    $migration->remember('subject_for_plan', fn (): array => $subjectForPlan);
    $out = [];

    foreach ((new SeriationStep)->batches($migration) as $batch) {
        $out[$batch->template] = ['file' => $batch->file, 'rows' => array_values([...$batch->rows]), 'incidents' => $batch->incidents];
    }

    return $out;
}

/**
 * Las incidencias de todos los lotes del paso, como `nivel|fila|mensaje`.
 *
 * @param  array<string, array{incidents: list<array{level: string, ref: string, message: string}>}>  $run
 * @return list<string>
 */
function seriationStepMessages(array $run): array
{
    return collect($run)->flatMap(fn (array $batch): array => $batch['incidents'])->map(fn (array $i): string => "{$i['level']}|{$i['ref']}|{$i['message']}")->values()->all();
}

/**
 * Las filas de `_incidencias.csv` del paso `seriacion` como `nivel|fila|mensaje`.
 *
 * @return list<string>
 */
function seriationIncidentLines(string $dir): array
{
    $rows = array_map(str_getcsv(...), array_slice(file("{$dir}/_incidencias.csv", FILE_IGNORE_NEW_LINES), 1));

    return array_values(array_map(fn (array $row): string => "{$row[4]}|{$row[2]}|{$row[5]}", array_filter($rows, fn (array $row): bool => $row[0] === 'seriacion')));
}

beforeEach(function () {
    $this->dir = SiiFixtures::dir();
    File::copyDirectory(base_path('tests/Fixtures/imports/sii/minimo'), $this->dir);
});

afterEach(fn () => File::deleteDirectory($this->dir));

describe('el paso', function () {
    beforeEach(function () {
        $this->plan = StudyPlan::factory()->create(['code' => 'AAA-2010-224']);
        $this->old = StudyPlan::factory()->create(['code' => 'AAA-2004-100']);

        foreach (['ACF-0901' => 1, 'ACF-0902' => 2, 'AEF-1001' => 3] as $code => $semester) {
            placeInPlan($this->plan, Subject::factory()->create(['code' => $code]), $semester);
        }

        placeInPlan($this->old, Subject::factory()->create(['code' => 'OLD1']), 1);
        placeInPlan($this->old, Subject::factory()->create(['code' => 'OLD2']), 2);

        foreach (['ZZZ1', 'ZZZ2'] as $code) {
            StudyPlanSubjectPending::factory()->for($this->plan)->for(Subject::factory()->create(['code' => $code]), 'subject')->create();
        }
    });

    it('es el paso siguiente a materias', function () {
        $steps = ImportsServiceProvider::SII_STEPS;

        expect($steps[array_search(SubjectsStep::class, $steps, true) + 1])->toBe(SeriationStep::class)
            ->and((new SeriationStep)->name())->toBe('seriacion');
    });

    it('P y C de materias de la retícula van al lote de seriación, por plan de Nexus', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'P'], ['A', '1', 'QU1', 'CD2', 'C'], ['A', '3', 'CD2', 'CD1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect(array_keys($run))->toBe(['prerequisites', 'pending_prerequisites'])
            ->and($run['prerequisites']['rows'])->toBe([
                ['plan' => 'AAA-2004-100', 'subject' => 'OLD2', 'prerequisite' => 'OLD1', 'kind' => 'seriacion'],
                ['plan' => 'AAA-2010-224', 'subject' => 'ACF-0902', 'prerequisite' => 'ACF-0901', 'kind' => 'seriacion'],
                ['plan' => 'AAA-2010-224', 'subject' => 'AEF-1001', 'prerequisite' => 'ACF-0902', 'kind' => 'correquisito'],
            ])
            ->and($run['pending_prerequisites']['rows'])->toBe([]);
    });

    it('lleva una clave numérica del SII a su materia', function () {
        writeSiiRequisites($this->dir, [['A', '1', '101', 'CD2', 'P']]);

        expect(seriationStepRun($this->dir)['prerequisites']['rows'])->toBe([['plan' => 'AAA-2010-224', 'subject' => 'AEF-1001', 'prerequisite' => 'ACF-0902', 'kind' => 'seriacion']]);
    });

    it('un requisito con una materia en «Por validar», o las dos, queda esperando con su retícula del SII', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'ZZ1', 'CD1', 'P'], ['A2', '2', 'CD2', 'ZZ1', 'C'], ['A', '1', 'ZZ2', 'ZZ1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect($run['prerequisites']['rows'])->toBe([])
            ->and($run['pending_prerequisites']['rows'])->toBe([
                ['plan' => 'AAA-2010-224', 'subject' => 'ACF-0902', 'prerequisite' => 'ZZZ1', 'kind' => 'correquisito', 'sii_grid' => 'A2/2'],
                ['plan' => 'AAA-2010-224', 'subject' => 'ZZZ1', 'prerequisite' => 'ACF-0901', 'kind' => 'seriacion', 'sii_grid' => 'A/1'],
                ['plan' => 'AAA-2010-224', 'subject' => 'ZZZ2', 'prerequisite' => 'ZZZ1', 'kind' => 'seriacion', 'sii_grid' => 'A/1'],
            ]);
    });

    it('una retícula que no se migra avisa una vez con cuántos requisitos no pasan', function () {
        writeSiiRequisites($this->dir, [['X', '9', 'XA', 'XB', 'P'], ['X', '9', 'XC', 'XB', 'C'], ['A', '1', 'CD2', 'CD1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect(seriationStepMessages($run))->toBe(['warning|X/9|Retícula X/9 excluida del curado: 2 requisitos de la seriación no se migran.'])
            ->and($run['prerequisites']['rows'])->toHaveCount(1);
    });

    it('una clave del SII sin materia de Nexus en el plan avisa una vez por plan y clave', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'NOPE', 'P'], ['A2', '2', 'NOPE', 'QU1', 'C'], ['A', '3', 'CD2', 'NOPE', 'P'], ['A', '3', 'QU1', 'CD1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect(seriationStepMessages($run))->toBe([
            'warning|AAA-2004-100/NOPE|La clave del SII NOPE no tiene materia de Nexus en el plan AAA-2004-100: 1 requisito de la seriación que la nombra no se migra.',
            'warning|AAA-2004-100/QU1|La clave del SII QU1 no tiene materia de Nexus en el plan AAA-2004-100: 1 requisito de la seriación que la nombra no se migra.',
            'warning|AAA-2010-224/NOPE|La clave del SII NOPE no tiene materia de Nexus en el plan AAA-2010-224: 2 requisitos de la seriación que la nombran no se migran.',
        ])->and($run['prerequisites']['rows'])->toBe([]);
    });

    it('una materia que se pide a sí misma, o dos claves que son la misma materia de Nexus, no se migran y avisan', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD1', 'CD1', 'P'], ['A2', '2', 'CD1D', 'CD1', 'C']]);

        $run = seriationStepRun($this->dir);

        expect(seriationStepMessages($run))->toBe([
            'warning|AAA-2010-224/ACF-0901|La retícula A/1 del SII pide la materia CD1 como requisito de sí misma: no se migra.',
            'warning|AAA-2010-224/ACF-0901|Las claves del SII CD1D y CD1 de la retícula A2/2 son la misma materia de Nexus, ACF-0901, en el plan AAA-2010-224: el requisito no se migra.',
        ])->and($run['prerequisites']['rows'])->toBe([])->and($run['pending_prerequisites']['rows'])->toBe([]);
    });

    it('un tipo de requisito que no es P ni C es error del renglón', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'P'], ['A', '1', 'QU1', 'CD2', 'X']]);

        $run = seriationStepRun($this->dir);

        expect(seriationStepMessages($run))->toBe(['error|requisitos_materia.csv:3|El tipo de requisito «X» de esta línea no es P (pre-requisito) ni C (co-requisito): no se migra.'])
            ->and($run['prerequisites']['rows'])->toHaveCount(1);
    });

    it('el mismo requisito con P en una retícula y C en otra del mismo plan queda como seriación y avisa (supuesto por confirmar)', function () {
        writeSiiRequisites($this->dir, [['A2', '2', 'CD2', 'CD1D', 'C'], ['A', '1', 'CD2', 'CD1', 'P'], ['A', '1', 'QU1', 'CD2', 'C'], ['A2', '2', 'QU1', 'CD2', 'C']]);

        $run = seriationStepRun($this->dir);

        expect($run['prerequisites']['rows'])->toBe([
            ['plan' => 'AAA-2010-224', 'subject' => 'ACF-0902', 'prerequisite' => 'ACF-0901', 'kind' => 'seriacion'],
            ['plan' => 'AAA-2010-224', 'subject' => 'AEF-1001', 'prerequisite' => 'ACF-0902', 'kind' => 'correquisito'],
        ])->and(collect(seriationStepMessages($run))->filter(fn (string $m): bool => str_contains($m, 'lo más estricto'))->values()->all())->toBe([
            'warning|AAA-2010-224/ACF-0902|El SII trae a ACF-0901 como requisito de ACF-0902 en el plan AAA-2010-224 como pre-requisito (P) en A/1 y como co-requisito (C) en A2/2: queda como seriación, lo más estricto (supuesto por confirmar).',
        ]);
    });

    it('una materia que no está en la retícula ni en «Por validar» del plan es error y el requisito no se migra', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'FAN', 'CD1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect(seriationStepMessages($run))->toBe(['error|AAA-2010-224/FANTASMA|El requisito ACF-0901 de FANTASMA del plan AAA-2010-224 no se migra: FANTASMA no está en la retícula ni en «Por validar» del plan.'])
            ->and($run['prerequisites']['rows'])->toBe([])->and($run['pending_prerequisites']['rows'])->toBe([]);
    });

    it('avisa, sin dejar de migrarlo, un P cuyo requisito no va en un semestre anterior y un C entre semestres distintos', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD1', 'CD2', 'P'], ['A', '1', 'CD2', 'CD1', 'C'], ['A', '1', 'QU1', 'CD1', 'P']]);

        $run = seriationStepRun($this->dir);

        expect($run['prerequisites']['rows'])->toHaveCount(3)
            ->and(seriationStepMessages($run))->toBe([
                'warning|AAA-2010-224/ACF-0901|En el plan AAA-2010-224, ACF-0901 (semestre 1) pide en seriación a ACF-0902 (semestre 2), que no va en un semestre anterior: es dato del SII y se migra igual.',
                'warning|AAA-2010-224/ACF-0902|En el plan AAA-2010-224, ACF-0902 (semestre 2) y su correquisito ACF-0901 (semestre 1) van en semestres distintos: es dato del SII y se migra igual.',
            ]);
    });

    it('sin requisitos_materia en la exportación, avisa una vez y no migra seriación', function () {
        $run = seriationStepRun($this->dir);

        expect(array_keys($run))->toBe(['prerequisites'])
            ->and($run['prerequisites']['rows'])->toBe([])
            ->and(seriationStepMessages($run))->toBe(['warning|seriacion|La exportación no trae requisitos_materia: la seriación del SII no se migra.']);
    });

    it('necesita los mapas de carreras y materias de la misma corrida', function () {
        writeSiiRequisites($this->dir, []);
        $migration = new SiiMigration(SiiExport::open($this->dir), '/nonexistent');

        expect(fn () => iterator_to_array((new SeriationStep)->batches($migration)))->toThrow(SiiStepOutOfOrder::class, 'el paso seriacion necesita que corran antes carreras y materias (falta el mapa plan_for)');

        $migration->remember('plan_for', fn (): array => SERIATION_STEP_PLAN_FOR);

        expect(fn () => iterator_to_array((new SeriationStep)->batches($migration)))->toThrow(SiiStepOutOfOrder::class, 'falta el mapa subject_for_plan');
    });
});

describe('con el motor de importación', function () {
    beforeEach(function () {
        Storage::fake('imports');
        Campus::factory()->create(['code' => 'C1']);
        User::factory()->admin()->create(['username' => 'migrador']);
        $this->curated = SiiFixtures::dir();

        File::copy(base_path('app/Modules/Imports/Sii/Curated/organigrama.csv'), $this->curated.'/organigrama.csv');
        File::put($this->curated.'/carreras.csv', "carrera,nombre,departamento,departamento_nombre,nota\nAAA,Carrera Triple A,110700,Económico Administrativas,\n");
        File::put($this->curated.'/planes.csv', "sii_carrera,sii_reticula,plan,carrera,modalidad,incluir,alumnos_vigentes,nota\nA,1,AAA-2010-224,AAA,presencial,si,0,\n");

        $areas = array_column((new SiiMigration(SiiExport::open($this->dir), $this->curated))->curated('organigrama'), 'clave_sii');
        File::put($this->dir.'/organigrama.csv', "clave_area,descripcion_area,area_depende,nivel,tipo_area,p_sustantivos,pro_sus,p_sus,p_s\n"
            .implode("\n", array_map(fn (string $area): string => "{$area},AREA {$area},100000,1,X,,,,", $areas))."\n");
        SiiFixtures::write($this->dir.'/carreras.csv', SiiFile::Carreras, [SiiFixtures::line(SiiFile::Carreras, ['carrera' => "'A'", 'reticula' => "'1'", 'fecha_inicio' => "'15/08/2010'"])]);

        $subject = fn (string $materia, string $name): string => SiiFixtures::line(SiiFile::Materias, ['materia' => "'{$materia}'", 'tipo_materia' => '1', 'clave_area' => "'110100'", 'nombre_completo_materia' => "'{$name}'"]);
        SiiFixtures::write($this->dir.'/materias.csv', SiiFile::Materias, [
            $subject('CD1', 'CALCULO DIFERENCIAL'), $subject('CD2', 'CALCULO INTEGRAL'), $subject('QU1', 'QUIMICA'), $subject('ZZ1', 'MATERIA SIN PAR'),
        ]);
        $placement = fn (string $materia, string $semester): string => SiiFixtures::line(SiiFile::MateriasCarreras, [
            'carrera' => "'A'", 'reticula' => '1', 'materia' => "'{$materia}'", 'creditos_materia' => '5', 'horas_teoricas' => '3', 'horas_practicas' => '2',
            'semestre_reticula' => $semester, 'especialidad' => "''", 'estatus_materia_carrera' => "'A'", 'renglon' => '1',
        ]);
        SiiFixtures::write($this->dir.'/materias_carreras.csv', SiiFile::MateriasCarreras, [$placement('CD1', '1'), $placement('CD2', '2'), $placement('QU1', '3'), $placement('ZZ1', '0')]);
        SiiFixtures::write($this->dir.'/materias_claves_tecnm.csv', SiiFile::MateriasClavesTecnm, [
            SiiFixtures::line(SiiFile::MateriasClavesTecnm, ['carrera' => "'A'", 'reticula' => '1', 'materia' => "'CD1'", 'clave_tecnm' => "'ACF-0901'"]),
        ]);
        SiiFixtures::write($this->dir.'/especialidades.csv', SiiFile::Especialidades, []);

        app()->when(RunSiiMigration::class)->needs('$curatedDir')->give($this->curated);

        $this->migrate = fn () => $this->artisan('imports:migrate-sii', [
            'dir' => $this->dir, '--user' => 'migrador', '--only' => ['organigrama', 'carreras', 'materias', 'seriacion'],
        ])->assertSuccessful();
    });

    afterEach(fn () => File::deleteDirectory($this->curated));

    it('P y C entran a la seriación del plan, lo de «Por validar» queda esperando y repetir la corrida no duplica ni cambia nada', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'P'], ['A', '1', 'QU1', 'CD2', 'C'], ['A', '1', 'ZZ1', 'CD1', 'P']]);

        ($this->migrate)();

        $seriation = fn (): array => SubjectPrerequisite::query()->with(['subject', 'prerequisite'])->get()
            ->map(fn (SubjectPrerequisite $row): string => "{$row->subject->code}<{$row->prerequisite->code}:{$row->kind->value}")->sort()->values()->all();
        $waiting = StudyPlanPrerequisitePending::query()->with(['subject', 'prerequisite'])->sole();
        $first = ImportBatch::query()->whereIn('template', ['prerequisites', 'pending_prerequisites'])->get();

        expect($seriation())->toBe(['CD2<ACF-0901:seriacion', 'QU1<CD2:correquisito'])
            ->and($waiting)->kind->toBe(PrerequisiteKind::Seriacion)->sii_grid->toBe('A/1')->applied_at->toBeNull()
            ->and("{$waiting->subject->code}<{$waiting->prerequisite->code}")->toBe('ZZ1<ACF-0901')
            ->and($first->map(fn (ImportBatch $batch): array => [$batch->template, $batch->created_rows, $batch->error_rows])->sortBy(0)->values()->all())
            ->toBe([['pending_prerequisites', 1, 0], ['prerequisites', 2, 0]])
            ->and(seriationIncidentLines($this->dir))->toContain('warning|AAA-2010-224/QU1|En el plan AAA-2010-224, QU1 (semestre 3) y su correquisito CD2 (semestre 2) van en semestres distintos: es dato del SII y se migra igual.');

        ($this->migrate)();

        $second = ImportBatch::query()->whereIn('template', ['prerequisites', 'pending_prerequisites'])->whereNotIn('id', $first->modelKeys())->get();

        expect($second)->toHaveCount(2)
            ->and($second->sum('created_rows') + $second->sum('updated_rows') + $second->sum('error_rows'))->toBe(0)
            ->and($seriation())->toBe(['CD2<ACF-0901:seriacion', 'QU1<CD2:correquisito'])
            ->and(StudyPlanPrerequisitePending::query()->count())->toBe(1);
    });

    it('una segunda corrida actualiza el tipo de un requisito que ya está y no borra la seriación capturada en Nexus', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'P']]);
        ($this->migrate)();
        $plan = StudyPlan::query()->where('code', 'AAA-2010-224')->sole();
        $code = fn (string $code): Subject => Subject::query()->where('code', $code)->sole();
        seriate($plan, $code('QU1'), $code('ACF-0901'));

        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'C']]);
        ($this->migrate)();

        expect(SubjectPrerequisite::query()->where('subject_id', $code('CD2')->id)->sole()->kind)->toBe(PrerequisiteKind::Correquisito)
            ->and(SubjectPrerequisite::query()->where('subject_id', $code('QU1')->id)->sole()->kind)->toBe(PrerequisiteKind::Seriacion);
    });

    it('un ciclo de P dentro del SII deja el renglón que lo cierra como error del lote', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'CD2', 'CD1', 'P'], ['A', '1', 'CD1', 'CD2', 'P']]);

        ($this->migrate)();

        expect(SubjectPrerequisite::query()->count())->toBe(1)
            ->and(ImportBatch::query()->where('template', 'prerequisites')->sole()->error_rows)->toBe(1)
            ->and(collect(seriationIncidentLines($this->dir))->filter(fn (string $line): bool => str_starts_with($line, 'error|'))->values()->all())
            ->toBe(['error|3|Cierra un ciclo de seriación: CD2 ya pide, en cadena, a ACF-0901.']);
    });

    it('el requisito que esperaba entra a la seriación cuando Servicios Escolares le asigna semestre a la materia', function () {
        writeSiiRequisites($this->dir, [['A', '1', 'ZZ1', 'CD1', 'P']]);
        ($this->migrate)();

        app(ResolvePendingPlacement::class)->handle(StudyPlanSubjectPending::query()->sole(), 4, User::query()->sole());

        expect(SubjectPrerequisite::query()->with(['subject', 'prerequisite'])->sole())
            ->subject->code->toBe('ZZ1')->prerequisite->code->toBe('ACF-0901')->kind->toBe(PrerequisiteKind::Seriacion)
            ->and(StudyPlanPrerequisitePending::query()->sole()->applied_at)->not->toBeNull();
    });
});
