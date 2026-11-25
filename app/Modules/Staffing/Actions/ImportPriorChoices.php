<?php

namespace App\Modules\Staffing\Actions;

use App\Modules\Academic\Actions\SetSubjectRequiredSpace;
use App\Modules\Academic\Enums\RequiredSpace;
use App\Modules\Academic\Models\StudyPlan;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Scheduling\Enums\GroupStatus;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Support\LoadRelationRow;
use App\Modules\Scheduling\Support\LoadRelationSheet;
use App\Modules\Scheduling\Support\SubjectResolver;
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
 * - **Grupo:** del periodo, la modalidad y el campus, de la materia y el plan del renglón, no cancelado, cuyo código es la
 *   letra del renglón. Sin horario fijo (D3) es un grupo sin sesiones de esa materia y plan: el k-ésimo renglón así de la
 *   materia y el plan es el k-ésimo grupo sin sesiones, en el orden en que se crearon (el de la carga de 1.40).
 * - **Todo o nada por docente:** si algún renglón de un docente es un error, no se registra su elección previa (quedaría a
 *   medias y el docente saldría de la orden sin tener todos sus grupos); los demás docentes siguen. Un docente cuyo registro
 *   rechaza RecordPriorChoice (HC-01, grupo de otro) se reporta en cada uno de sus renglones.
 * - **Tipo de espacio (decisión del usuario del 25 de noviembre de 2026):** de cada renglón, con o sin maestro, si la materia
 *   tiene `required_space` vacío se llena con SetSubjectRequiredSpace desde la columna LABORATORIO / SALÓN (LABORATORIO y
 *   SALA DE COMPUTO = `sala_computo`, porque en las relaciones de Sistemas «laboratorio» es sala de cómputo; SALON y AULA =
 *   `aula`; LABORATORIO DE REDES = `laboratorio`); si dos renglones de la materia difieren, gana el primero y se avisa. Nunca pisa un valor ya revisado.
 *
 * Idempotente. Con `$dryRun` corre el mismo registro dentro de una transacción que se deshace (así también reporta HC-01) y
 * no llena tipos de espacio.
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
        $subjects = [];
        $spaces = [];
        $pools = [];
        $sessionless = [];
        $byTeacher = [];
        $failed = [];

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

            if ($problem === null) {
                $group = $this->findGroup($row, $term, $campus, $modality, $subject['id'], $plan->id, $sessionless);

                if (is_string($group)) {
                    $problem = $group;
                    $group = null;
                }
            }

            $key = $teacher ?? 'name:'.TeacherNameMatcher::normalize($row->teacherName);
            $byTeacher[$key]['name'] = $row->teacherName;
            $byTeacher[$key]['rows'][] = $row->line;

            if ($problem !== null) {
                $report['errors'][] = ['row' => $row->line, 'message' => $problem];
                $failed[$key] = true;

                continue;
            }

            $byTeacher[$key]['id'] = $teacher;
            $byTeacher[$key]['groups'][$group->getKey()] = $group;
        }

        $report['teachers'] = count($byTeacher);

        foreach ($byTeacher as $key => $item) {
            if (isset($failed[$key])) {
                $report['warnings'][] = "{$item['name']}: no se registró su elección previa porque algún renglón (".implode(', ', $item['rows']).') tiene error.';

                continue;
            }

            $this->recordTeacher($report, $item, $term, $modality, $actor, $dryRun);
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

        usort($report['errors'], fn (array $a, array $b): int => $a['row'] <=> $b['row']);

        return $report;
    }

    /**
     * @param  array{id: ?string, name: string, rows: list<int>, groups: array<string, Group>}  $item
     * @param  array<string, mixed>  $report
     */
    private function recordTeacher(array &$report, array $item, Term $term, Modality $modality, User $actor, bool $dryRun): void
    {
        $teacher = Teacher::query()->findOrFail($item['id']);
        $groups = array_values($item['groups']);
        $new = count(array_filter($groups, fn (Group $group): bool => $group->teacher_id !== $teacher->getKey()));

        if ($dryRun) {
            DB::beginTransaction();
        }

        try {
            $this->record->handle($term, $modality, $teacher, array_map(fn (Group $group): array => ['group' => $group], $groups), $actor);
            $report['recorded']++;
            $report['groups_assigned'] += $new;
        } catch (ValidationException $exception) {
            $message = collect($exception->errors())->flatten()->first();

            foreach ($item['rows'] as $line) {
                $report['errors'][] = ['row' => $line, 'message' => "{$item['name']}: {$message}"];
            }
        } finally {
            if ($dryRun) {
                DB::rollBack();
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
        $tokens = fn (string $text): array => collect(explode(' ', TeacherNameMatcher::normalize($text)))->filter()->unique()->sort()->values()->all();
        $target = $tokens($name);

        return $target === [] ? [] : $pool->filter(fn (Teacher $teacher): bool => $tokens($teacher->name) === $target)->pluck('id')->values()->all();
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
     * El grupo del renglón, o el motivo (texto) si no se encuentra.
     *
     * @param  array<string, int>  $sessionless  cuántos renglones sin horario fijo se han asignado por materia y plan
     */
    private function findGroup(LoadRelationRow $row, Term $term, Campus $campus, Modality $modality, string $subjectId, string $planId, array &$sessionless): Group|string
    {
        $base = Group::query()
            ->where('term_id', $term->getKey())->where('modality_id', $modality->getKey())->where('campus_id', $campus->getKey())
            ->where('subject_id', $subjectId)->where('study_plan_id', $planId)
            ->where('status', '!=', GroupStatus::Cancelled->value);

        if ($row->hasFixedSchedule()) {
            $groups = (clone $base)->where('code', $row->letter)->get();

            return $groups->count() === 1
                ? $groups->first()
                : "No hay un grupo {$row->letter} de {$row->subjectKey} en {$campus->code} ({$modality->code}) para el plan {$row->planCode}.";
        }

        $index = $sessionless["{$subjectId}|{$planId}"] = ($sessionless["{$subjectId}|{$planId}"] ?? -1) + 1;
        $group = (clone $base)->whereDoesntHave('sessions')->orderBy('created_at')->orderBy('id')->skip($index)->first();

        return $group ?? "No hay un grupo sin horario fijo de {$row->subjectKey} para el plan {$row->planCode} (el renglón es el {$this->ordinal($index)} sin horario de la materia).";
    }

    private function ordinal(int $index): string
    {
        return ($index + 1).'.º';
    }
}
