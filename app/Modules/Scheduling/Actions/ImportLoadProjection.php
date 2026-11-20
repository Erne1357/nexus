<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Rules\CreditDays;
use App\Modules\Scheduling\Rules\GroupCode;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Support\ProjectionSheet;
use App\Modules\Scheduling\Support\SubjectResolver;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga en Nexus la proyección de carga de un departamento (hoja «formato proyecto docente», ProjectionSheet): por cada
 * fila válida, un grupo planeado del periodo, la modalidad y el campus indicados, con su cupo dueño y sus sesiones de
 * una hora, sin salón ni docente (D4, confirmado el 20 de noviembre de 2026; reglas-de-negocio.md, R-PRY-1).
 *
 * - **Plan:** la clave está en el texto de la columna PLAN (`ISIC-2010-224. INGENIERÍA…`): se busca en `study_plans.code`. El
 *   plan da la carrera dueña. Sin clave o sin plan, la fila se reporta.
 * - **Materia:** `subjects.code` exacta; luego sin espacios y con o sin guion (CID2501 = CID-2501); luego `subject_aliases` y,
 *   si existe `sii_archivo`, la clave TecNM de `materias_claves_tecnm` (como ImportSiiGroupHistory). Nunca crea materias.
 * - **Departamento:** el de la materia (FK compuesta). Su carga del periodo y la modalidad debe existir y estar en borrador,
 *   propuesta, asignación, ajustes o re-análisis; si no, la fila se reporta (supuesto por confirmar).
 * - **Hora:** la de la columna HORA («9 - 10»); si el archivo trae la fórmula sin valor guardado, la que da la letra del grupo
 *   (B = 8:00 …, la misma de GroupCode). `#N/A`, sin grupo o sin alumnos: la fila se omite y se reporta.
 * - **Días:** CreditDays (créditos). Créditos sin regla: fila reportada.
 * - **Clave:** AssignGroupCode; si no coincide con la letra del archivo, se avisa.
 * - **Idempotente:** mismo periodo, modalidad, materia, carrera dueña, semestre y hora de inicio = mismo grupo (no cancelado):
 *   se omite con aviso.
 *
 * Una sola transacción, bitácora con el resumen y, al final, el recálculo de choques. Con `$dryRun` calcula el mismo
 * reporte sin escribir nada.
 */
final class ImportLoadProjection
{
    private const int LETTERS = 15;

    public function __construct(
        private readonly ProjectionSheet $sheet,
        private readonly SyncScheduleConflicts $conflicts,
    ) {}

    /**
     * @return array{dry_run: bool, rows: int, created: int, sessions: int, per_department: array<string, int>, resolved_via: array<string, int>, skipped: list<array{row: int, kind: string, message: string}>, skipped_by_kind: array<string, int>, unresolved_subjects: array<string, int>, warnings: list<string>, conflicts: ?array{opened: int, kept: int, resolved: int}}
     *
     * @throws RuntimeException
     */
    public function handle(string $path, Term $term, Campus $campus, Modality $modality, bool $dryRun = false): array
    {
        $rows = $this->sheet->rows($path);
        $report = ['dry_run' => $dryRun, 'rows' => count($rows), 'created' => 0, 'sessions' => 0, 'per_department' => [], 'resolved_via' => [], 'skipped' => [], 'skipped_by_kind' => [], 'unresolved_subjects' => [], 'warnings' => [], 'conflicts' => null];

        $plans = StudyPlan::query()->get(['id', 'code', 'career_id'])->keyBy(fn (StudyPlan $plan): string => mb_strtoupper($plan->code));
        $resolver = new SubjectResolver;
        $departments = DB::table('departments')->pluck('code', 'id')->all();
        $existing = $this->existingGroups($term, $modality);
        $codes = [];
        $loadOk = [];
        $plan = [];

        foreach ($rows as $row) {
            $semester = $this->integer($row['semester']);
            $students = $this->integer($row['students']);
            $credits = $this->integer($row['credits']);

            if ($row['code'] === '' || $row['group'] === '' || $students === null || $students < 1 || $semester === null || $semester < 1) {
                $this->skip($report, $row['row'], 'omitted', 'Sin clave de materia, grupo, alumnos o semestre.');

                continue;
            }

            if ($row['hourIsError'] || $row['hour'] === '') {
                $this->skip($report, $row['row'], 'omitted', "El grupo {$row['group']} de {$row['code']} no tiene hora (#N/A o vacía).");

                continue;
            }

            $start = $this->startMinutes($row['hour'], $row['group']);

            if ($start === null) {
                $this->skip($report, $row['row'], 'omitted', "El grupo {$row['group']} de {$row['code']} no tiene una hora legible ({$row['hour']}).");

                continue;
            }

            [$startMinutes, $endMinutes] = $start;

            if (! preg_match('/^([A-Z]{2,6}-\d{4}-\d{2,4})/u', mb_strtoupper(trim($row['plan'])), $match)) {
                $this->skip($report, $row['row'], 'no_plan', "El bloque «{$row['plan']}» no trae la clave del plan.");

                continue;
            }

            $studyPlan = $plans->get($match[1]);

            if ($studyPlan === null) {
                $this->skip($report, $row['row'], 'unknown_plan', "Nexus no tiene el plan {$match[1]}.");

                continue;
            }

            $subject = $resolver->resolve($row['code']);

            if ($subject === null) {
                $report['unresolved_subjects'][$row['code']] = ($report['unresolved_subjects'][$row['code']] ?? 0) + 1;
                $this->skip($report, $row['row'], 'unresolved_subject', "Nexus no tiene la materia {$row['code']}.");

                continue;
            }

            $report['resolved_via'][$subject['via']] = ($report['resolved_via'][$subject['via']] ?? 0) + 1;
            $days = $credits === null ? null : CreditDays::for($credits);

            if ($days === null) {
                $this->skip($report, $row['row'], 'bad_credits', "La materia {$row['code']} trae créditos sin regla de días ({$row['credits']}).");

                continue;
            }

            $departmentId = $subject['department_id'];
            $loadOk[$departmentId] ??= $this->loadState($term, $modality, $departmentId);

            if ($loadOk[$departmentId] !== true) {
                $this->skip($report, $row['row'], $loadOk[$departmentId], "El departamento {$departments[$departmentId]} ".($loadOk[$departmentId] === 'no_load' ? "no tiene carga en {$term->code} ({$modality->code})" : "tiene la carga de {$term->code} ({$modality->code}) en un estado que ya no se edita").'.');

                continue;
            }

            $key = $subject['id'].'|'.$studyPlan->career_id.'|'.$semester.'|'.$startMinutes;

            if (isset($existing[$key])) {
                $this->skip($report, $row['row'], 'duplicate', "El grupo {$row['group']} de {$row['code']} (semestre {$semester}, ".Minutes::format($startMinutes).') ya existe.');

                continue;
            }

            $existing[$key] = true;
            $plan[] = compact('row', 'subject', 'studyPlan', 'semester', 'students', 'days', 'startMinutes', 'endMinutes', 'departmentId');
        }

        // La clave que tendrá cada grupo, en el orden del archivo, para avisar si la letra del archivo no coincide.
        foreach ($plan as $item) {
            $taken = $codes[$item['subject']['id']] ??= Group::query()->active()->where('term_id', $term->id)->where('subject_id', $item['subject']['id'])->pluck('code')->all();
            $code = GroupCode::forStart($item['startMinutes'], $taken);
            $codes[$item['subject']['id']][] = $code;

            if ($code !== $item['row']['group']) {
                $report['warnings'][] = "Fila {$item['row']['row']}: el grupo {$item['row']['group']} de {$item['row']['code']} queda con la clave {$code} (la letra sale de la hora de inicio).";
            }

            $report['created']++;
            $report['sessions'] += count($item['days']);
            $dept = $departments[$item['departmentId']];
            $report['per_department'][$dept] = ($report['per_department'][$dept] ?? 0) + 1;
        }

        arsort($report['unresolved_subjects']);
        ksort($report['per_department']);

        if ($dryRun || $plan === []) {
            return $report;
        }

        DB::transaction(function () use ($plan, $term, $campus, $modality, $report): void {
            foreach ($plan as $item) {
                $this->createGroup($item, $term, $campus, $modality);
            }

            activity()
                ->event('load_projection_imported')
                ->withProperties([
                    'term' => $term->code, 'campus' => $campus->code, 'modality' => $modality->code,
                    'rows' => $report['rows'], 'created' => $report['created'], 'sessions' => $report['sessions'],
                    'per_department' => $report['per_department'], 'skipped' => $report['skipped_by_kind'],
                    'unresolved_subjects' => array_keys($report['unresolved_subjects']),
                ])
                ->log("Cargó la proyección de carga en {$term->code} ({$modality->code}, {$campus->code}): {$report['created']} grupos");
        });

        $report['conflicts'] = $this->conflicts->handle($term, $modality);

        return $report;
    }

    /**
     * @param  array{row: array<string, mixed>, subject: array{id: string, department_id: string, via: string}, studyPlan: StudyPlan, semester: int, students: int, days: array<int, int>, startMinutes: int, endMinutes: int, departmentId: string}  $item
     */
    private function createGroup(array $item, Term $term, Campus $campus, Modality $modality): void
    {
        DB::select('select pg_advisory_xact_lock(hashtext(?))', ["groups:{$term->id}:{$item['subject']['id']}"]);

        $taken = Group::query()->active()->where('term_id', $term->id)->where('subject_id', $item['subject']['id'])->pluck('code')->all();

        $group = Group::query()->create([
            'term_id' => $term->id,
            'campus_id' => $campus->id,
            'modality_id' => $modality->id,
            'subject_id' => $item['subject']['id'],
            'department_id' => $item['departmentId'],
            'owner_career_id' => $item['studyPlan']->career_id,
            'study_plan_id' => $item['studyPlan']->id,
            'semester' => $item['semester'],
            'code' => GroupCode::provisional($taken),
            'capacity' => $item['students'],
            'status' => GroupStatus::Planned,
        ]);

        GroupQuota::query()->create([
            'group_id' => $group->id,
            'career_id' => $item['studyPlan']->career_id,
            'max_seats' => $item['students'],
            'is_owner' => true,
        ]);

        $now = now();
        $sessions = [];
        $length = $item['endMinutes'] - $item['startMinutes'];

        foreach ($item['days'] as $day => $hours) {
            $sessions[] = [
                'group_id' => $group->id,
                'day' => $day,
                'starts_at' => Minutes::format($item['startMinutes']),
                'ends_at' => Minutes::format($item['startMinutes'] + $length * $hours),
                'kind' => 'teoria',
                'room_id' => null,
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('group_sessions')->insert($sessions);

        app(AssignGroupCode::class)->handle($group);
    }

    /**
     * Los grupos no cancelados que ya hay, por materia, carrera dueña, semestre y hora de inicio.
     *
     * @return array<string, true>
     */
    private function existingGroups(Term $term, Modality $modality): array
    {
        $keys = [];

        $rows = DB::table('groups')
            ->join('group_sessions', 'group_sessions.group_id', '=', 'groups.id')
            ->where('groups.term_id', $term->id)->where('groups.modality_id', $modality->id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->groupBy('groups.id', 'groups.subject_id', 'groups.owner_career_id', 'groups.semester')
            ->selectRaw('groups.subject_id, groups.owner_career_id, groups.semester, min(group_sessions.starts_at) as start')
            ->get();

        foreach ($rows as $row) {
            $keys[$row->subject_id.'|'.$row->owner_career_id.'|'.$row->semester.'|'.Minutes::of((string) $row->start)] = true;
        }

        return $keys;
    }

    /**
     * `true` si la carga del departamento existe y se puede editar; si no, la clave del motivo.
     */
    private function loadState(Term $term, Modality $modality, string $departmentId): true|string
    {
        $load = AcademicLoad::query()->ofDepartment($term->id, $modality->id, $departmentId)->first(['id', 'status']);

        if ($load === null) {
            return 'no_load';
        }

        return $load->status === LoadStatus::Draft || in_array($load->status, LoadEditability::EDITABLE, true) ? true : 'load_not_editable';
    }

    /**
     * Inicio y fin en minutos: de «9 - 10» o «9:30 - 10:30»; sin valor guardado (fórmula sin calcular), de la letra del grupo.
     *
     * @return array{int, int}|null
     */
    private function startMinutes(?string $hour, string $letter): ?array
    {
        if ($hour === null) {
            $index = strlen($letter) === 1 ? ord($letter) - ord('A') : -1;

            return $index >= 0 && $index < self::LETTERS ? [(7 + $index) * 60, (8 + $index) * 60] : null;
        }

        if (preg_match('/^(\d{1,2})(?::(\d{2}))?\s*-\s*(\d{1,2})(?::(\d{2}))?$/', $hour, $m) !== 1) {
            return null;
        }

        $start = (int) $m[1] * 60 + (int) ($m[2] ?? 0);
        $end = (int) $m[3] * 60 + (int) ($m[4] ?? 0);

        return $end > $start && $end <= 24 * 60 ? [$start, $end] : null;
    }

    private function integer(mixed $value): ?int
    {
        if (is_int($value)) {
            return $value;
        }

        if (is_float($value) && floor($value) === $value) {
            return (int) $value;
        }

        return is_string($value) && preg_match('/^\s*\d+\s*$/', $value) === 1 ? (int) $value : null;
    }

    /**
     * @param  array<string, mixed>  $report
     */
    private function skip(array &$report, int $row, string $kind, string $message): void
    {
        $report['skipped'][] = ['row' => $row, 'kind' => $kind, 'message' => $message];
        $report['skipped_by_kind'][$kind] = ($report['skipped_by_kind'][$kind] ?? 0) + 1;
    }
}
