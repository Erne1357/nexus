<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Academic\Actions\SetSubjectRequiredSpace;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Academic\Models\Subject;
use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\LoadEditability;
use App\Modules\Scheduling\Support\LoadRelationRow;
use App\Modules\Scheduling\Support\LoadRelationSheet;
use App\Modules\Scheduling\Support\SubjectResolver;
use App\Modules\Scheduling\Validation\Minutes;
use App\Modules\Staffing\Rules\TeacherNameMatcher;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;

/**
 * Carga las elecciones previas (SEL-16, tarea 1.41) de la «Relación de materias» (LoadRelationSheet, 1.40): por cada
 * renglón con MAESTRO busca al docente y su grupo, y por docente llama a RecordPriorChoice con todos sus grupos.
 *
 * - **Docente:** mismo conjunto de palabras normalizadas (la relación escribe «APELLIDOS NOMBRES», Nexus «Nombres Apellidos») entre los
 *   docentes activos adscritos al departamento de la materia; sin coincidencia exacta o con más de una, el renglón es un error.
 * - **Grupo:** del periodo, la modalidad y el campus, no cancelado. Con horario fijo, por la llave de 1.40: materia, plan,
 *   semestre (el del renglón o el de la materia en el plan) y minuto de inicio del rango; la letra del archivo no cuenta
 *   (la de Nexus sale de la hora). Sin horario fijo (D3) es un grupo sin sesiones: primero uno que ya sea del docente y no se
 *   haya usado en la corrida; si no, el k-ésimo grupo sin sesiones, con k = el lugar del renglón entre TODOS los renglones
 *   sin horario fijo de su materia, plan y semestre (con o sin maestro, sin los que 1.40 omite).
 * - **Todo o nada por docente** (el docente es el nombre del renglón, aunque no empate): si algún renglón suyo es un error, no se registra su elección previa (quedaría a
 *   medias y el docente saldría de la orden sin tener todos sus grupos); los demás docentes siguen. Un docente cuyo registro
 *   rechaza RecordPriorChoice (HC-01, grupo de otro) se reporta en cada uno de sus renglones.
 * - **Tipo de espacio (decisión del usuario del 25 de noviembre de 2026):** de cada renglón, con o sin maestro, si la materia
 *   tiene `required_space` vacío se llena con SetSubjectRequiredSpace desde la columna LABORATORIO / SALÓN (LABORATORIO y
 *   SALA DE COMPUTO = `sala_computo`, porque en las relaciones de Sistemas «laboratorio» es sala de cómputo; SALON y AULA =
 *   `aula`; LABORATORIO DE REDES = `laboratorio`); si dos renglones de la materia difieren, gana el primero y se avisa. Nunca pisa un valor ya revisado.
 *
 * Idempotente. Con `$dryRun` toda la corrida va en una sola transacción que se deshace al final (así reporta HC-01 y los
 * choques entre docentes) y no llena tipos de espacio.
 */
final class ImportPriorChoices
{
    public function __construct(
        private readonly RecordPriorChoice $record,
        private readonly SetSubjectRequiredSpace $setSpace,
    ) {}

    /**
     * @return array{dry_run: bool, rows: int, rows_with_teacher: int, teachers: int, recorded: int, groups_assigned: int, spaces_set: int, errors: list<array{row: int, message: string}>, warnings: list<string>}
     *
     * @throws RuntimeException
     */
    public function handle(string $path, Term $term, Campus $campus, Modality $modality, User $actor, bool $dryRun = false): array
    {
        $rows = LoadRelationSheet::read($path);
        $report = ['dry_run' => $dryRun, 'rows' => count($rows), 'rows_with_teacher' => 0, 'teachers' => 0, 'recorded' => 0, 'groups_assigned' => 0, 'spaces_set' => 0, 'errors' => [], 'warnings' => []];

        $plans = StudyPlan::query()->get(['id', 'code'])->keyBy(fn (StudyPlan $plan): string => mb_strtoupper($plan->code));
        $resolver = new SubjectResolver;
        $spaces = [];
        $pools = [];
        $byTeacher = [];
        $failed = [];
        $used = [];
        $positions = $this->sessionlessPositions($rows, $plans, $resolver, $term, $modality);

        foreach ($rows as $row) {
            $subject = $resolver->resolve($row->subjectKey);

            if ($subject !== null) {
                $this->collectSpace($report, $row, $subject['id'], $spaces);
            }

            if ($row->teacherName === null) {
                continue;
            }

            $report['rows_with_teacher']++;
            $problem = null;

            if ($subject === null) {
                $problem = "Nexus no tiene la materia {$row->subjectKey}.";
            } elseif (($plan = $row->planCode === null ? null : $plans->get(mb_strtoupper($row->planCode))) === null) {
                $problem = $row->planCode === null ? 'El renglón no trae la clave del plan.' : "Nexus no tiene el plan {$row->planCode}.";
            }

            $teacher = null;
            $group = null;

            if ($problem === null) {
                $pool = $pools[$subject['department_id']] ??= Teacher::query()
                    ->where('is_active', true)
                    ->whereHas('adscriptions', fn ($query) => $query->where('department_id', $subject['department_id']))
                    ->get(['id', 'name']);
                $candidates = $this->candidates($row->teacherName, $pool);

                if (count($candidates) !== 1) {
                    $problem = $candidates === []
                        ? "No hay un docente activo del departamento de la materia que se llame «{$row->teacherName}»."
                        : "«{$row->teacherName}» coincide con más de un docente del departamento (".count($candidates).'): resuélvelo a mano.';
                } else {
                    $teacher = $candidates[0];
                }
            }

            // Todo o nada por docente: el docente es el nombre del renglón (su conjunto de palabras), no el id empatado,
            // porque un renglón que no empata no tiene id y aun así su docente no debe registrarse a medias.
            $key = implode(' ', $this->tokens($row->teacherName));
            $byTeacher[$key]['name'] ??= $row->teacherName;
            $byTeacher[$key]['rows'][] = $row->line;

            if ($teacher !== null) {
                $byTeacher[$key]['ids'][$teacher] = true;

                if (count($byTeacher[$key]['ids']) > 1) {
                    $problem = "«{$row->teacherName}» empata con docentes distintos en los departamentos de sus materias: resuélvelo a mano.";
                }
            }

            if ($problem === null) {
                $group = $this->findGroup($row, $term, $campus, $modality, $subject['id'], $plan->id, $teacher, $positions[$row->line] ?? null, $used[$key] ?? []);

                if (is_string($group)) {
                    $problem = $group;
                    $group = null;
                }
            }

            if ($problem !== null) {
                $report['errors'][] = ['row' => $row->line, 'message' => $problem];
                $failed[$key] = true;

                continue;
            }

            $used[$key][$group->getKey()] = true;
            $byTeacher[$key]['groups'][$group->getKey()] = $group;
        }

        $report['teachers'] = count($byTeacher);

        // Una sola transacción para toda la corrida: en --dry-run se deshace al final, así un docente ve lo que dejó el
        // anterior (por ejemplo, el mismo grupo en dos docentes es un choque) sin escribir nada.
        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            foreach ($byTeacher as $key => $item) {
                if (isset($failed[$key])) {
                    $report['warnings'][] = "{$item['name']}: no se registró su elección previa porque algún renglón (".implode(', ', $item['rows']).') tiene error.';

                    continue;
                }

                $this->recordTeacher($report, $item, $term, $modality, $actor);
            }

            if (! $dryRun) {
                foreach ($spaces as $subjectId => $space) {
                    $subject = Subject::query()->find($subjectId);

                    if ($subject !== null && $subject->required_space === null) {
                        $this->setSpace->handle($subject, $space, $actor);
                        $report['spaces_set']++;
                    }
                }
            } else {
                $report['spaces_set'] = collect($spaces)->keys()->filter(fn (string $id): bool => Subject::query()->whereKey($id)->whereNull('required_space')->exists())->count();
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
            }
        }

        usort($report['errors'], fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return $report;
    }

    /**
     * @param  array{ids?: array<string, true>, name: string, rows: list<int>, groups: array<string, Group>}  $item
     * @param  array<string, mixed>  $report
     */
    private function recordTeacher(array &$report, array $item, Term $term, Modality $modality, User $actor): void
    {
        $teacher = Teacher::query()->findOrFail(array_key_first($item['ids']));
        $groups = array_values($item['groups']);
        $new = count(array_filter($groups, fn (Group $group): bool => $group->teacher_id !== $teacher->getKey()));

        try {
            $this->record->handle($term, $modality, $teacher, array_map(fn (Group $group): array => ['group' => $group], $groups), $actor);
            $report['recorded']++;
            $report['groups_assigned'] += $new;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            foreach ($item['rows'] as $line) {
                $report['errors'][] = ['row' => $line, 'message' => "{$item['name']}: {$message}"];
            }
        }
    }

    /**
     * Los docentes del grupo cuyo nombre tiene el mismo conjunto de palabras que el del renglón: la relación escribe
     * «APELLIDOS NOMBRES» y Nexus «Nombres Apellidos», así que no se compara el orden. Sin acentos, en mayúsculas y con los
     * espacios colapsados. No adivina: sin el mismo conjunto exacto no hay candidato.
     *
     * @param  Collection<int, Teacher>  $pool
     * @return list<string>
     */
    private function candidates(string $name, $pool): array
    {
        $target = $this->tokens($name);

        return $target === [] ? [] : $pool->filter(fn (Teacher $teacher): bool => $this->tokens($teacher->name) === $target)->pluck('id')->values()->all();
    }

    /**
     * El conjunto de palabras normalizadas de un nombre, ordenado.
     *
     * @return list<string>
     */
    private function tokens(string $text): array
    {
        return collect(explode(' ', TeacherNameMatcher::normalize($text)))->filter()->unique()->sort()->values()->all();
    }

    /**
     * El tipo de espacio de la columna LABORATORIO / SALÓN (S2): gana el primer renglón de cada materia.
     *
     * @param  array<string, mixed>  $report
     * @param  array<string, RequiredSpace>  $spaces
     */
    private function collectSpace(array &$report, LoadRelationRow $row, string $subjectId, array &$spaces): void
    {
        $space = match ($row->space) {
            'LABORATORIO', 'SALA DE COMPUTO' => RequiredSpace::SalaComputo,
            'SALON', 'AULA' => RequiredSpace::Aula,
            'LABORATORIO DE REDES' => RequiredSpace::Laboratorio,
            default => null,
        };

        if ($space === null) {
            return;
        }

        if (isset($spaces[$subjectId]) && $spaces[$subjectId] !== $space) {
            $report['warnings'][] = "Renglón {$row->line}: {$row->subjectKey} trae {$space->label()} pero un renglón anterior de la materia ya pidió {$spaces[$subjectId]->label()}; gana el primero.";

            return;
        }

        $spaces[$subjectId] ??= $space;
    }

    /**
     * El semestre del renglón o, sin él (o menor que 1), el de la materia en el plan (`study_plan_subjects`); null si no hay.
     */
    private function semesterOf(LoadRelationRow $row, string $planId, string $subjectId): ?int
    {
        return $row->semester !== null && $row->semester >= 1
            ? $row->semester
            : StudyPlanSubject::query()->where('study_plan_id', $planId)->where('subject_id', $subjectId)->value('semester');
    }

    /**
     * El lugar (desde 0) de cada renglón sin horario fijo entre TODOS los renglones sin horario fijo de su materia, plan y
     * semestre, con o sin maestro, en el orden del archivo: 1.40 crea un grupo sin sesiones por cada uno, en ese orden, así
     * que el k-ésimo renglón es el k-ésimo grupo. Los renglones que 1.40 omite (sin alumnos, materia o plan que Nexus no
     * tiene, sin semestre, carga que no se edita) no cuentan y no tienen lugar.
     *
     * @param  list<LoadRelationRow>  $rows
     * @param  Collection<string, StudyPlan>  $plans
     * @return array<int, int> línea del renglón => lugar
     */
    private function sessionlessPositions(array $rows, $plans, SubjectResolver $resolver, Term $term, Modality $modality): array
    {
        $positions = [];
        $counters = [];
        $editable = [];

        foreach ($rows as $row) {
            if ($row->hasFixedSchedule() || $row->subjectKey === '' || $row->students === null || $row->students < 1) {
                continue;
            }

            $subject = $resolver->resolve($row->subjectKey);
            $plan = $row->planCode === null ? null : $plans->get(mb_strtoupper($row->planCode));

            if ($subject === null || $plan === null) {
                continue;
            }

            $editable[$subject['department_id']] ??= ($load = AcademicLoad::query()->ofDepartment($term->getKey(), $modality->getKey(), $subject['department_id'])->first(['id', 'status'])) !== null
                && in_array($load->status, [LoadStatus::Draft, ...LoadEditability::EDITABLE], true);
            $semester = $this->semesterOf($row, $plan->id, $subject['id']);

            if (! $editable[$subject['department_id']] || $semester === null) {
                continue;
            }

            $key = "{$subject['id']}|{$plan->id}|{$semester}";
            $positions[$row->line] = $counters[$key] = ($counters[$key] ?? -1) + 1;
        }

        return $positions;
    }

    /**
     * El grupo del renglón, o el motivo (texto) si no se encuentra.
     *
     * - **Con horario fijo:** la llave de 1.40 (periodo, modalidad, campus, materia, plan, semestre y minuto de inicio de
     *   las sesiones), no la letra del archivo (la letra de Nexus sale de la hora). Uno solo; ninguno o varios es un error.
     * - **Sin horario fijo:** primero un grupo sin sesiones de la materia, plan y semestre que ya sea del docente y no se
     *   haya usado en esta corrida (así repetir no asigna otro); si no, el que ocupa el lugar del renglón entre los grupos sin
     *   sesiones (en el orden en que se crearon), y si ese ya lo tomó el mismo docente, uno libre.
     *
     * @param  ?int  $position  el lugar del renglón sin horario fijo (sessionlessPositions)
     * @param  array<string, true>  $used  los grupos que este docente ya tomó en la corrida
     */
    private function findGroup(LoadRelationRow $row, Term $term, Campus $campus, Modality $modality, string $subjectId, string $planId, ?string $teacherId, ?int $position, array $used): Group|string
    {
        $semester = $this->semesterOf($row, $planId, $subjectId);

        if ($semester === null) {
            return "El renglón no trae semestre y {$row->subjectKey} no está en la retícula del plan {$row->planCode}.";
        }

        $base = Group::query()
            ->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())->where('campus_id', $campus->getKey())
            ->where('subject_id', $subjectId)->where('study_plan_id', $planId)->where('semester', $semester)
            ->where('status', '!=', GroupStatus::Cancelled->value);

        if ($row->hasFixedSchedule()) {
            $start = $row->startMinute();
            $groups = $start === null ? collect() : (clone $base)->with('sessions:id,group_id,starts_at')->get()
                ->filter(fn (Group $group): bool => $group->sessions->isNotEmpty() && $group->sessions->map(fn ($session): int => Minutes::of((string) $session->starts_at))->min() === $start);

            return $groups->count() === 1
                ? $groups->first()
                : ($groups->isEmpty()
                    ? "No hay un grupo de {$row->subjectKey} en {$campus->code} ({$modality->code}) para el plan {$row->planCode}, semestre {$semester}, que empiece a las ".($start === null ? '(hora ilegible)' : Minutes::label($start)).'.'
                    : "Hay {$groups->count()} grupos de {$row->subjectKey} en {$campus->code} para el plan {$row->planCode}, semestre {$semester}, a las ".Minutes::label($start).': resuélvelo a mano.');
        }

        if ($position === null) {
            return "La importación de la carga (1.40) omite este renglón de {$row->subjectKey} (sin alumnos, sin semestre o con la carga que ya no se edita), así que no tiene grupo.";
        }

        $sessionless = (clone $base)->whereDoesntHave('sessions')->orderBy('created_at')->orderBy('id');
        $own = $teacherId === null ? null : (clone $sessionless)->where('teacher_id', $teacherId)->whereNotIn('id', array_keys($used))->first();

        if ($own !== null) {
            return $own;
        }

        $group = (clone $sessionless)->skip($position)->first();

        if ($group !== null && isset($used[$group->getKey()])) {
            $group = (clone $sessionless)->whereNull('teacher_id')->whereNotIn('id', array_keys($used))->first();
        }

        return $group ?? "No hay un grupo sin horario fijo de {$row->subjectKey} para el plan {$row->planCode} (el renglón es el {$this->ordinal($position)} sin horario de la materia).";
    }

    private function ordinal(int $index): string
    {
        return ($index + 1).'.º';
    }
}
