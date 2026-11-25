<?php

namespace App\Modules\Scheduling\Actions;

use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Facilities\Models\Room;
use App\Modules\Scheduling\Actions\Sessions\AssignSessionRoom;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Models\GroupQuota;
use App\Modules\Scheduling\Models\GroupSession;
use App\Modules\Scheduling\Rules\CreditDays;
use App\Modules\Scheduling\Rules\GroupCode;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Support\LoadRelationRow;
use App\Modules\Scheduling\Support\LoadRelationSheet;
use App\Modules\Scheduling\Support\ProjectionSheet;
use App\Modules\Scheduling\Support\SubjectResolver;
use App\Modules\Scheduling\Validation\Minutes;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
 * - **Idempotente:** mismo periodo, modalidad, campus, materia, carrera dueña, plan, semestre y hora de inicio = mismo grupo (no cancelado).
 *   Dos grupos en paralelo a la misma hora comparten llave: los que ya existen cubren las primeras filas y solo se crean las
 *   que sobran (las demás se omiten con aviso).
 *
 * **«Relación de materias» (`.csv`, LoadRelationSheet, 1.40):** misma Action con otro lector. El plan sale de la clave al inicio de
 * CLAVE CARRERA; el semestre vacío toma el de la materia en el plan (`study_plan_subjects`); la hora es el rango de la fila. Con
 * letra «GL» o rango vacío o `#N/D` el grupo se crea **sin sesiones** (D3, confirmado el 25 de noviembre de 2026) y su llave de
 * idempotencia usa hora nula. La columna SALÓN se busca por código en el campus y se pone con AssignSessionRoom en todas las
 * sesiones del grupo (nuevo o existente) solo si el grupo no tiene salón y el salón está libre; si no, aviso. No toca maestros
 * ni `subjects.required_space` (Staffing, 1.41) ni el estado de la carga.
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
     * @return array{dry_run: bool, rows: int, created: int, existing: int, no_schedule: int, rooms_set: int, rooms_requested: int, sessions: int, per_department: array<string, int>, resolved_via: array<string, int>, skipped: list<array{row: int, kind: string, message: string}>, skipped_by_kind: array<string, int>, unresolved_subjects: array<string, int>, warnings: list<string>, conflicts: ?array{opened: int, kept: int, resolved: int}}
     *
     * @throws RuntimeException
     */
    public function handle(string $path, Term $term, Campus $campus, Modality $modality, bool $dryRun = false, ?User $actor = null): array
    {
        $isRelation = LoadRelationSheet::isRelation($path);

        if (! $isRelation && strtolower(pathinfo($path, PATHINFO_EXTENSION)) === 'csv') {
            throw new RuntimeException('El archivo csv no es la relación de materias: no trae el encabezado «CLAVE DE MATERIA».');
        }

        $rows = $isRelation ? $this->relationRows($path) : $this->sheet->rows($path);
        $report = ['dry_run' => $dryRun, 'rows' => count($rows), 'created' => 0, 'existing' => 0, 'no_schedule' => 0, 'rooms_set' => 0, 'rooms_requested' => 0, 'sessions' => 0, 'per_department' => [], 'resolved_via' => [], 'skipped' => [], 'skipped_by_kind' => [], 'unresolved_subjects' => [], 'warnings' => [], 'conflicts' => null];

        $plans = StudyPlan::query()->get(['id', 'code', 'career_id'])->keyBy(fn (StudyPlan $plan): string => mb_strtoupper($plan->code));
        $resolver = new SubjectResolver;
        $departments = DB::table('departments')->pluck('code', 'id')->all();
        $existing = $this->existingGroups($term, $modality, $campus);
        $seen = [];
        $codes = [];
        $loadOk = [];
        $plan = [];
        $existingRooms = [];

        foreach ($rows as $row) {
            $relation = $row['relation'];
            $semester = $this->integer($row['semester']);
            $students = $this->integer($row['students']);
            $credits = $this->integer($row['credits']);
            $fixed = $row['fixed'];

            $missing = array_keys(array_filter([
                'clave de materia' => $row['code'] === '',
                'grupo' => ! $relation && $row['group'] === '',
                'alumnos' => $students === null || $students < 1,
                'semestre' => ! $relation && ($semester === null || $semester < 1),
            ]));

            if ($missing !== []) {
                $this->skip($report, $row['row'], 'omitted', 'Fila sin '.implode(', ', $missing).'.');

                continue;
            }

            $startMinutes = $endMinutes = null;

            if ($fixed) {
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
            }

            if (! preg_match('/^([A-Z]{2,6}-\d{4}-\d{2,4})/u', mb_strtoupper(trim($row['plan'])), $match)) {
                $this->skip($report, $row['row'], 'no_plan', $relation ? 'El renglón no trae la clave del plan.' : "El bloque «{$row['plan']}» no trae la clave del plan.");

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

            if ($semester === null) {
                $semester = StudyPlanSubject::query()->where('study_plan_id', $studyPlan->id)->where('subject_id', $subject['id'])->value('semester');

                if ($semester === null) {
                    $this->skip($report, $row['row'], 'no_semester', "El renglón no trae semestre y {$row['code']} no está en la retícula del plan {$match[1]}.");

                    continue;
                }
            }

            if ($fixed && $credits !== null && CreditDays::for($credits) !== null && CreditDays::isAssumed($credits)) {
                $report['warnings'][] = "Fila {$row['row']}: {$credits} créditos usan la regla de días que es supuesto por confirmar (R-PRY-1).";
            }

            if ($fixed && $credits !== null && CreditDays::unplacedHours($credits) > 0) {
                $report['warnings'][] = "Fila {$row['row']}: {$credits} créditos: se acomodan de lunes a viernes a la hora del archivo y falta acomodar a mano ".CreditDays::unplacedHours($credits).' hora (sin ella, el sábado o una segunda el viernes, según la materia; R-PRY-1).';
            }

            $report['resolved_via'][$subject['via']] = ($report['resolved_via'][$subject['via']] ?? 0) + 1;
            $days = [];

            if ($fixed) {
                $days = $credits === null ? null : CreditDays::for($credits);

                if ($days !== null && $startMinutes + ($endMinutes - $startMinutes) * max($days) > 24 * 60) {
                    $days = null;
                }

                if ($days === null) {
                    $this->skip($report, $row['row'], 'bad_credits', "La materia {$row['code']} trae créditos sin regla de días ({$row['credits']}).");

                    continue;
                }
            }

            $departmentId = $subject['department_id'];
            $loadOk[$departmentId] ??= $this->loadState($term, $modality, $departmentId);

            if ($loadOk[$departmentId] !== true) {
                $this->skip($report, $row['row'], $loadOk[$departmentId], "El departamento {$departments[$departmentId]} ".($loadOk[$departmentId] === 'no_load' ? "no tiene carga en {$term->code} ({$modality->code})" : "tiene la carga de {$term->code} ({$modality->code}) en un estado que ya no se edita").'.');

                continue;
            }

            $key = implode('|', [$subject['id'], $studyPlan->career_id, $studyPlan->id, $campus->id, $semester, $startMinutes ?? 'none']);
            $seen[$key] = ($seen[$key] ?? 0) + 1;

            // Dos grupos de la misma materia a la misma hora (A y B en paralelo) son dos filas con la misma llave: los que ya
            // existen cubren las primeras filas y solo las que sobran se crean.
            if ($seen[$key] <= count($existing[$key] ?? [])) {
                $report['existing']++;

                if (! $relation) {
                    $this->skip($report, $row['row'], 'duplicate', "El grupo {$row['group']} de {$row['code']} (semestre {$semester}, ".Minutes::format($startMinutes).') ya existe.');
                }

                if ($row['room'] !== null) {
                    $existingRooms[] = ['line' => $row['row'], 'group_id' => $existing[$key][$seen[$key] - 1], 'room' => $row['room']];
                }

                continue;
            }

            $room = $row['room'];
            $plan[] = compact('row', 'subject', 'studyPlan', 'semester', 'students', 'days', 'startMinutes', 'endMinutes', 'departmentId', 'room');
        }

        // La clave que tendrá cada grupo, en el orden del archivo, para avisar si la letra del archivo no coincide.
        foreach ($plan as $item) {
            $report['created']++;
            $dept = $departments[$item['departmentId']];
            $report['per_department'][$dept] = ($report['per_department'][$dept] ?? 0) + 1;

            if ($item['startMinutes'] === null) {
                $report['no_schedule']++;

                if ($item['room'] !== null) {
                    $report['warnings'][] = "Fila {$item['row']['row']}: el grupo {$item['row']['code']} no tiene horario fijo y no recibe salón.";
                }

                continue;
            }

            $taken = $codes[$item['subject']['id']] ??= Group::query()->active()->where('term_id', $term->id)->where('subject_id', $item['subject']['id'])->pluck('code')->all();
            $code = GroupCode::forStart($item['startMinutes'], $taken);
            $codes[$item['subject']['id']][] = $code;

            if ($item['row']['group'] !== '' && $code !== $item['row']['group']) {
                $report['warnings'][] = "Fila {$item['row']['row']}: el grupo {$item['row']['group']} de {$item['row']['code']} queda con la clave {$code} (la letra sale de la hora de inicio).";
            }

            $report['sessions'] += count($item['days']);
        }

        arsort($report['unresolved_subjects']);
        ksort($report['per_department']);

        $roomsAsked = array_merge(
            array_map(fn (array $item): array => ['line' => $item['row']['row'], 'room' => $item['room'], 'group_id' => null], array_filter($plan, fn (array $item): bool => $item['room'] !== null && $item['startMinutes'] !== null)),
            $existingRooms,
        );

        if ($dryRun) {
            foreach ($roomsAsked as $asked) {
                // Un grupo existente que ya tiene salón o no tiene sesiones no recibe uno.
                if ($asked['group_id'] !== null && ! $this->canReceiveRoom($asked['group_id'])) {
                    continue;
                }

                $report['rooms_requested']++;
                [$room, $problem] = $this->findRoom($campus, $asked['room']);

                if ($room === null) {
                    $report['warnings'][] = "Fila {$asked['line']}: {$problem}";
                }
            }

            return $report;
        }

        if ($plan === [] && $existingRooms === []) {
            return $report;
        }

        DB::transaction(function () use ($plan, $existingRooms, $term, $campus, $modality, $actor, &$report): void {
            foreach ($plan as $item) {
                $group = $this->createGroup($item, $term, $campus, $modality);

                if ($item['room'] !== null && $item['startMinutes'] !== null) {
                    $this->placeRoom($report, $group, $item['room'], $item['row']['row'], $campus, $actor);
                }
            }

            foreach ($existingRooms as $asked) {
                $this->placeRoom($report, Group::query()->findOrFail($asked['group_id']), $asked['room'], $asked['line'], $campus, $actor);
            }

            activity()
                ->causedBy($actor)
                ->event('load_projection_imported')
                ->withProperties([
                    'term' => $term->code, 'campus' => $campus->code, 'modality' => $modality->code,
                    'rows' => $report['rows'], 'created' => $report['created'], 'existing' => $report['existing'],
                    'no_schedule' => $report['no_schedule'], 'rooms_set' => $report['rooms_set'], 'sessions' => $report['sessions'],
                    'per_department' => $report['per_department'], 'skipped' => $report['skipped_by_kind'],
                    'unresolved_subjects' => array_keys($report['unresolved_subjects']),
                ])
                ->log("Cargó la proyección de carga en {$term->code} ({$modality->code}, {$campus->code}): {$report['created']} grupos");
        });

        $report['conflicts'] = $this->conflicts->handle($term, $modality);

        return $report;
    }

    /**
     * Los renglones de la relación con la forma que usa el ciclo de handle(): sin horario fijo (`fixed` falso) si la letra es
     * GL o el rango falta o es `#N/D` (D3).
     *
     * @return list<array<string, mixed>>
     */
    private function relationRows(string $path): array
    {
        return array_map(fn (LoadRelationRow $row): array => [
            'row' => $row->line,
            'relation' => true,
            'plan' => $row->planCode ?? '',
            'semester' => $row->semester !== null && $row->semester < 1 ? null : $row->semester,
            'subject' => $row->subjectName,
            'code' => $row->subjectKey,
            'credits' => $row->credits,
            'group' => $row->letter ?? '',
            'students' => $row->students,
            'hour' => $row->hourRange,
            'hourIsError' => false,
            'fixed' => $row->hasFixedSchedule(),
            'room' => $row->roomCode,
        ], LoadRelationSheet::read($path));
    }

    /**
     * El salón del campus con ese código (exacto, o sin importar mayúsculas si no hay uno exacto); si dos salones activos
     * responden al mismo código no se adivina: sin salón y su motivo.
     *
     * @return array{?Room, ?string} el salón, o el motivo por el que no hay
     */
    private function findRoom(Campus $campus, string $code): array
    {
        $matches = Room::query()->where('campus_id', $campus->id)->whereRaw('upper(code) = ?', [mb_strtoupper($code)])->get();
        $exact = $matches->where('code', $code);

        if ($exact->count() === 1) {
            return [$exact->first(), null];
        }

        if ($matches->where('is_active', true)->count() > 1) {
            return [null, "hay más de un salón activo con la clave {$code} en {$campus->code}; el grupo queda sin salón."];
        }

        return $matches->isEmpty()
            ? [null, "el salón {$code} no existe en {$campus->code}; el grupo queda sin salón."]
            : [$matches->first(), null];
    }

    private function canReceiveRoom(string $groupId): bool
    {
        $sessions = GroupSession::query()->where('group_id', $groupId)->get(['room_id']);

        return $sessions->isNotEmpty() && $sessions->every(fn (GroupSession $session): bool => $session->room_id === null);
    }

    /**
     * Pone el salón del archivo en todas las sesiones del grupo con AssignSessionRoom, solo si ninguna tiene salón; si el
     * salón no existe, choca o la carga no se edita, deja el aviso y el grupo sin salón.
     *
     * @param  array<string, mixed>  $report
     */
    private function placeRoom(array &$report, Group $group, string $roomCode, int $line, Campus $campus, ?User $actor): void
    {
        [$room, $problem] = $this->findRoom($campus, $roomCode);

        if ($room === null) {
            $report['warnings'][] = "Fila {$line}: {$problem}";

            return;
        }

        $sessions = GroupSession::query()->where('group_id', $group->id)->get(['id', 'room_id']);

        if ($sessions->isEmpty() || $sessions->contains(fn (GroupSession $session): bool => $session->room_id !== null)) {
            return;
        }

        if ($actor === null) {
            $report['warnings'][] = "Fila {$line}: no hay un usuario activo para registrar el salón {$roomCode}; el grupo queda sin salón.";

            return;
        }

        try {
            app(AssignSessionRoom::class)->handleMany($group, $sessions->mapWithKeys(fn (GroupSession $session): array => [$session->id => $room])->all(), $actor, importing: true);
            $report['rooms_set']++;
        } catch (ValidationException $exception) {
            $report['warnings'][] = "Fila {$line}: el salón {$roomCode} no se puso en el grupo {$group->code}: ".collect($exception->errors())->flatten()->first();
        }
    }

    /**
     * @param  array{row: array<string, mixed>, subject: array{id: string, department_id: string, via: string}, studyPlan: StudyPlan, semester: int, students: int, days: array<int, int>, startMinutes: ?int, endMinutes: ?int, departmentId: string, room: ?string}  $item
     */
    private function createGroup(array $item, Term $term, Campus $campus, Modality $modality): Group
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

        if ($sessions !== []) {
            DB::table('group_sessions')->insert($sessions);
        }

        return app(AssignGroupCode::class)->handle($group);
    }

    /**
     * Los grupos no cancelados que ya hay, por materia, carrera dueña, plan, campus, semestre y hora de inicio (`none` si no
     * tienen sesiones), con sus ids en orden de alta.
     *
     * @return array<string, list<string>> los ids de grupo por llave
     */
    private function existingGroups(Term $term, Modality $modality, Campus $campus): array
    {
        $ids = [];

        $rows = DB::table('groups')
            ->leftJoin('group_sessions', 'group_sessions.group_id', '=', 'groups.id')
            ->where('groups.term_id', $term->id)->where('groups.modality_id', $modality->id)->where('groups.campus_id', $campus->id)
            ->where('groups.status', '<>', GroupStatus::Cancelled->value)
            ->groupBy('groups.id', 'groups.subject_id', 'groups.owner_career_id', 'groups.study_plan_id', 'groups.semester', 'groups.created_at')
            ->orderBy('groups.created_at')->orderBy('groups.id')
            ->selectRaw('groups.id, groups.subject_id, groups.owner_career_id, groups.study_plan_id, groups.semester, min(group_sessions.starts_at) as start')
            ->get();

        foreach ($rows as $row) {
            $key = implode('|', [$row->subject_id, $row->owner_career_id, $row->study_plan_id, $campus->id, $row->semester, $row->start === null ? 'none' : Minutes::of((string) $row->start)]);
            $ids[$key][] = $row->id;
        }

        return $ids;
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
