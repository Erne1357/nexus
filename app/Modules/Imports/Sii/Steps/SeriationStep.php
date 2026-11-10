<?php

namespace App\Modules\Imports\Sii\Steps;

use App\Modules\Academic\Enums\PrerequisiteKind;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\StudyPlanSubjectPending;
use App\Modules\Imports\Sii\SiiBatch;
use App\Modules\Imports\Sii\SiiExport;
use App\Modules\Imports\Sii\SiiFile;
use App\Modules\Imports\Sii\SiiMigration;
use App\Modules\Imports\Sii\SiiStep;
use App\Modules\Imports\Sii\SiiStepOutOfOrder;
use Illuminate\Support\Str;

/**
 * Seriación del SII (reglas-de-negocio.md, "Reglas de datos", migración de la seriación; decisión del usuario del 10 de
 * noviembre de 2026, confirmada). Cada renglón de `requisitos_materia` dice que `materia` pide a `materia_relacion` en
 * una retícula del SII: `tipo_requisito` P (pre-requisito) es seriación y C (co-requisito), correquisito. La retícula se
 * lleva a su plan de Nexus con `plan_for` y las dos claves a sus materias con `subject_for_plan` (las claves numéricas
 * son llaves int de PHP: se buscan con `$map[$clave] ?? null`). Corre después de `materias`, que deja la retícula y
 * «Por validar» aplicados: dónde está cada materia se lee de la base, también en un ensayo (misma transacción).
 *
 * - Retícula excluida del curado: una advertencia por retícula con cuántos renglones no pasan.
 * - Clave del SII sin materia de Nexus en ese plan: una advertencia por plan y clave con cuántos renglones la nombran.
 * - Las dos claves llevan a la misma materia de Nexus (la que se pide a sí misma, o dos claves fundidas): advertencia
 *   por renglón y no se migra.
 * - Otro `tipo_requisito`: error del renglón, por archivo y línea.
 * - El mismo requisito (plan, materia y requisito de Nexus) desde varios renglones es uno solo; si unos dicen P y otros
 *   C, queda seriación, lo más estricto, y avisa (supuesto por confirmar).
 * - Las dos materias en la retícula del plan: lote `prerequisites`, cuya plantilla revisa los ciclos (el renglón que
 *   cierra uno queda como error del lote). Primero van los que respetan el orden de los semestres, así en un ciclo
 *   entra el que pide una materia de un semestre anterior (supuesto por confirmar). Avisa sin dejar de migrarlo un P
 *   cuyo requisito no va en un semestre anterior y un C entre semestres distintos: es dato del SII.
 * - Alguna en «Por validar» del plan y la otra en la retícula o también en «Por validar»: lote `pending_prerequisites`
 *   (requisitos que esperan, con la retícula del SII de la que salieron), sin revisar ciclos: se revisan al aplicarse,
 *   cuando Servicios Escolares le asigna semestre (ApplyWaitingPrerequisites).
 * - Alguna materia que no está en la retícula ni en «Por validar» del plan (no pasó en `materias` o se quitó): error y
 *   no se migra.
 *
 * El archivo es opcional en la exportación: sin él, el paso avisa una vez y no migra seriación. Las incidencias van con
 * el lote `prerequisites`.
 */
final class SeriationStep implements SiiStep
{
    /** @var list<string> las únicas columnas de `requisitos_materia` que lee este paso */
    public const array COLUMNS = ['carrera', 'reticula', 'materia', 'materia_relacion', 'tipo_requisito'];

    /** @var array<string, PrerequisiteKind> */
    private const array KINDS = ['P' => PrerequisiteKind::Seriacion, 'C' => PrerequisiteKind::Correquisito];

    public function name(): string
    {
        return 'seriacion';
    }

    public function batches(SiiMigration $migration): iterable
    {
        /** @var array<string, array{plan: string, career: string, modality: string}> $planFor */
        $planFor = $migration->remember('plan_for', fn () => throw new SiiStepOutOfOrder($this->name(), 'plan_for', ['carreras', 'materias']));
        /** @var array<string, array<string|int, string>> $subjectForPlan */
        $subjectForPlan = $migration->remember('subject_for_plan', fn () => throw new SiiStepOutOfOrder($this->name(), 'subject_for_plan', ['materias']));
        $export = $migration->export();

        if (! $export->has(SiiFile::RequisitosMateria)) {
            yield new SiiBatch('prerequisites', 'seriacion.csv', [], incidents: [$this->incident('warning', 'seriacion', 'La exportación no trae requisitos_materia: la seriación del SII no se migra.')]);

            return;
        }

        $incidents = [];
        $pairs = $this->pairs($export, $planFor, $subjectForPlan, $incidents);
        [$prerequisites, $waiting] = $this->route($pairs, $incidents);

        yield new SiiBatch('prerequisites', 'seriacion.csv', $prerequisites, incidents: $incidents);
        yield new SiiBatch('pending_prerequisites', 'seriacion_por_validar.csv', $waiting);
    }

    /**
     * Los requisitos del SII llevados a plan y materias de Nexus, sin repetir: plan → materia → requisito → tipo → las
     * retículas del SII de donde vino. Anota lo que no pasa.
     *
     * @param  array<string, array{plan: string, career: string, modality: string}>  $planFor
     * @param  array<string, array<string|int, string>>  $subjectForPlan
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array<string, array<string, array<string, array<string, array<string, true>>>>>
     */
    private function pairs(SiiExport $export, array $planFor, array $subjectForPlan, array &$incidents): array
    {
        $file = $export->fileName(SiiFile::RequisitosMateria);
        $excluded = [];
        $missing = [];
        $same = [];
        $pairs = [];

        foreach ($export->reader(SiiFile::RequisitosMateria)->rows(self::COLUMNS) as $line => $row) {
            $grid = "{$row['carrera']}/{$row['reticula']}";

            if (! isset($planFor[$grid])) {
                $excluded[$grid] = ($excluded[$grid] ?? 0) + 1;

                continue;
            }

            $type = strtoupper($row['tipo_requisito']);
            $kind = self::KINDS[$type] ?? null;

            if ($kind === null) {
                $incidents[] = $this->incident('error', "{$file}:{$line}", "El tipo de requisito «{$row['tipo_requisito']}» de esta línea no es P (pre-requisito) ni C (co-requisito): no se migra.");

                continue;
            }

            $plan = $planFor[$grid]['plan'];
            $subject = $subjectForPlan[$plan][$row['materia']] ?? null;
            $prerequisite = $subjectForPlan[$plan][$row['materia_relacion']] ?? null;

            foreach ([$row['materia'] => $subject, $row['materia_relacion'] => $prerequisite] as $key => $code) {
                if ($code === null) {
                    $missing["{$plan}/{$key}"] = ($missing["{$plan}/{$key}"] ?? 0) + 1;
                }
            }

            if ($subject === null || $prerequisite === null) {
                continue;
            }

            $subject = Str::upper($subject);
            $prerequisite = Str::upper($prerequisite);

            if ($subject === $prerequisite) {
                $same["{$plan}|{$grid}|{$row['materia']}|{$row['materia_relacion']}"] ??= $this->incident('warning', "{$plan}/{$subject}", $row['materia'] === $row['materia_relacion']
                    ? "La retícula {$grid} del SII pide la materia {$row['materia']} como requisito de sí misma: no se migra."
                    : "Las claves del SII {$row['materia']} y {$row['materia_relacion']} de la retícula {$grid} son la misma materia de Nexus, {$subject}, en el plan {$plan}: el requisito no se migra.");

                continue;
            }

            $pairs[$plan][$subject][$prerequisite][$kind->value][$grid] = true;
        }

        ksort($excluded, SORT_STRING);
        foreach ($excluded as $grid => $count) {
            $incidents[] = $this->incident('warning', (string) $grid, "Retícula {$grid} excluida del curado: ".($count === 1 ? '1 requisito' : "{$count} requisitos").' de la seriación no se migran.');
        }

        ksort($missing, SORT_STRING);
        foreach ($missing as $ref => $count) {
            [$plan, $key] = explode('/', (string) $ref, 2);
            $incidents[] = $this->incident('warning', (string) $ref, "La clave del SII {$key} no tiene materia de Nexus en el plan {$plan}: "
                .($count === 1 ? '1 requisito de la seriación que la nombra no se migra.' : "{$count} requisitos de la seriación que la nombran no se migran."));
        }

        array_push($incidents, ...array_values($same));

        return $pairs;
    }

    /**
     * Reparte los requisitos entre la seriación (las dos materias en la retícula) y los que esperan (alguna en «Por
     * validar»), con un solo tipo por requisito.
     *
     * @param  array<string, array<string, array<string, array<string, array<string, true>>>>>  $pairs
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array{0: list<array<string, string>>, 1: list<array<string, string>>}
     */
    private function route(array $pairs, array &$incidents): array
    {
        [$grid, $pending] = $this->places(array_map('strval', array_keys($pairs)));
        ksort($pairs, SORT_STRING);
        $prerequisites = [];
        $waiting = [];

        foreach ($pairs as $plan => $subjects) {
            $plan = (string) $plan;
            ksort($subjects, SORT_STRING);
            $planRows = [];

            foreach ($subjects as $subject => $requisites) {
                $subject = (string) $subject;
                ksort($requisites, SORT_STRING);

                foreach ($requisites as $prerequisite => $kinds) {
                    $prerequisite = (string) $prerequisite;
                    [$kind, $siiGrid] = $this->kind($plan, $subject, $prerequisite, $kinds, $incidents);
                    $outside = array_values(array_filter([$subject, $prerequisite], fn (string $code): bool => ! isset($grid[$plan][$code]) && ! isset($pending[$plan][$code])));

                    if ($outside !== []) {
                        $incidents[] = $this->incident('error', "{$plan}/{$subject}", "El requisito {$prerequisite} de {$subject} del plan {$plan} no se migra: ".implode(' y ', $outside).' no '.(count($outside) === 1 ? 'está' : 'están').' en la retícula ni en «Por validar» del plan.');

                        continue;
                    }

                    if (! isset($grid[$plan][$subject], $grid[$plan][$prerequisite])) {
                        $waiting[] = ['plan' => $plan, 'subject' => $subject, 'prerequisite' => $prerequisite, 'kind' => $kind->value, 'sii_grid' => $siiGrid];

                        continue;
                    }

                    $semester = $grid[$plan][$subject];
                    $required = $grid[$plan][$prerequisite];
                    $backwards = $kind === PrerequisiteKind::Seriacion && $required >= $semester;

                    if ($backwards) {
                        $incidents[] = $this->incident('warning', "{$plan}/{$subject}", "En el plan {$plan}, {$subject} (semestre {$semester}) pide en seriación a {$prerequisite} (semestre {$required}), que no va en un semestre anterior: es dato del SII y se migra igual.");
                    } elseif ($kind === PrerequisiteKind::Correquisito && $required !== $semester) {
                        $incidents[] = $this->incident('warning', "{$plan}/{$subject}", "En el plan {$plan}, {$subject} (semestre {$semester}) y su correquisito {$prerequisite} (semestre {$required}) van en semestres distintos: es dato del SII y se migra igual.");
                    }

                    $planRows[$backwards ? 1 : 0][] = ['plan' => $plan, 'subject' => $subject, 'prerequisite' => $prerequisite, 'kind' => $kind->value];
                }
            }

            array_push($prerequisites, ...($planRows[0] ?? []), ...($planRows[1] ?? []));
        }

        return [$prerequisites, $waiting];
    }

    /**
     * El tipo de un requisito y la retícula del SII de la que sale: si el SII lo trae como P y como C, queda seriación y
     * avisa (supuesto por confirmar). La retícula es la primera, en orden, de las que traen el tipo que queda.
     *
     * @param  array<string, array<string, true>>  $kinds  tipo → retículas del SII
     * @param  list<array{level: 'error'|'warning', ref: string, message: string}>  $incidents
     * @return array{PrerequisiteKind, string}
     */
    private function kind(string $plan, string $subject, string $prerequisite, array $kinds, array &$incidents): array
    {
        $grids = array_map(function (array $grids): array {
            $grids = array_map('strval', array_keys($grids));
            sort($grids, SORT_STRING);

            return $grids;
        }, $kinds);

        $kind = isset($grids[PrerequisiteKind::Seriacion->value]) ? PrerequisiteKind::Seriacion : PrerequisiteKind::Correquisito;

        if (count($grids) > 1) {
            $incidents[] = $this->incident('warning', "{$plan}/{$subject}", "El SII trae a {$prerequisite} como requisito de {$subject} en el plan {$plan} como pre-requisito (P) en "
                .implode(', ', $grids[PrerequisiteKind::Seriacion->value]).' y como co-requisito (C) en '.implode(', ', $grids[PrerequisiteKind::Correquisito->value])
                .': queda como seriación, lo más estricto (supuesto por confirmar).');
        }

        return [$kind, $grids[$kind->value][0]];
    }

    /**
     * Dónde está cada materia de los planes: su semestre en la retícula (sin lo que se quitó ni las materias borradas del
     * catálogo) y las que esperan en «Por validar» (pendientes sin resolver), por clave del plan y de la materia.
     *
     * @param  list<string>  $plans
     * @return array{0: array<string, array<string, int>>, 1: array<string, array<string, true>>}
     */
    private function places(array $plans): array
    {
        $grid = [];
        $pending = [];

        StudyPlanSubject::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->whereNull('subjects.deleted_at')
            ->whereIn('study_plans.code', $plans)
            ->toBase()
            ->get(['study_plans.code as plan', 'subjects.code as subject', 'study_plan_subjects.semester'])
            ->each(function (object $row) use (&$grid): void {
                $grid[$row->plan][$row->subject] = (int) $row->semester;
            });

        StudyPlanSubjectPending::query()
            ->join('subjects', 'subjects.id', '=', 'study_plan_subject_pendings.subject_id')
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subject_pendings.study_plan_id')
            ->whereNull('subjects.deleted_at')
            ->whereNull('study_plan_subject_pendings.resolved_at')
            ->whereIn('study_plans.code', $plans)
            ->toBase()
            ->get(['study_plans.code as plan', 'subjects.code as subject'])
            ->each(function (object $row) use (&$pending): void {
                $pending[$row->plan][$row->subject] = true;
            });

        return [$grid, $pending];
    }

    /**
     * @param  'error'|'warning'  $level
     * @return array{level: 'error'|'warning', ref: string, message: string}
     */
    private function incident(string $level, string $ref, string $message): array
    {
        return ['level' => $level, 'ref' => $ref, 'message' => $message];
    }
}
