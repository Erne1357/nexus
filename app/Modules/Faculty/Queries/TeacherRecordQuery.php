<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use App\Modules\Faculty\Enums\QualificationLevel;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherDepartment;
use App\Modules\Faculty\Models\TeacherEvaluation;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherPosition;
use App\Modules\Faculty\Models\TeacherSubjectHistory;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use App\Modules\Faculty\Rules\BaseHours;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Collection;

/**
 * Lecturas de la ficha de un docente (tarea 1.13). Quién la ve lo decide TeacherPolicy (`view`) antes de llamarla.
 * Sin N+1: adscripciones con su departamento, plazas y usuario, en una consulta cada una; las horas base del
 * periodo salen de las plazas ya cargadas con la misma regla que la lista (BaseHours, S1 y S2, supuestos por
 * confirmar).
 */
final class TeacherRecordQuery
{
    /**
     * La ficha de `$teacher` en `$term` (el periodo activo; null si no hay). Las adscripciones y plazas borradas no
     * entran; el departamento de una adscripción sí, aunque esté borrado.
     */
    public function for(Teacher $teacher, ?Term $term): TeacherRecord
    {
        $adscriptions = $teacher->adscriptions()
            ->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name'])])
            ->orderByDesc('is_primary')
            ->orderBy('id')
            ->get()
            ->each(fn (TeacherDepartment $adscription) => $adscription->setRelation('teacher', $teacher));

        $today = today()->toDateString();
        $termDay = $term?->starts_on->toDateString();
        $order = ['vigente' => 0, 'por_empezar' => 1, 'vencida' => 2];
        $positions = $teacher->positions()->get();
        $status = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $this->status($position, $today)])->all();

        $positions = $positions
            ->sortBy([
                fn (TeacherPosition $a, TeacherPosition $b): int => $order[$status[$a->id]] <=> $order[$status[$b->id]],
                fn (TeacherPosition $a, TeacherPosition $b): int => strcmp((string) $b->valid_from?->toDateString(), (string) $a->valid_from?->toDateString()),
                fn (TeacherPosition $a, TeacherPosition $b): int => strcmp($a->id, $b->id),
            ])
            ->values();
        $status = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $status[$position->id]])->all();
        $counts = $positions->mapWithKeys(fn (TeacherPosition $position): array => [$position->id => $termDay !== null && $this->covers($position, $termDay)])->all();

        return new TeacherRecord(
            teacher: $teacher,
            adscriptions: $adscriptions,
            positions: $positions,
            positionStatus: $status,
            countsInTerm: $counts,
            baseHours: $term === null ? null : BaseHours::onDate(
                $positions->map(fn (TeacherPosition $position): array => [
                    'base_hours' => $position->base_hours,
                    'valid_from' => $position->valid_from?->toDateString(),
                    'valid_to' => $position->valid_to?->toDateString(),
                ]),
                $term->starts_on,
            ),
            term: $term,
            user: $teacher->user_id === null ? null : $teacher->user()->first(['id', 'username', 'is_active', 'must_change_password']),
        );
    }

    /**
     * Una adscripción sin borrar de ese docente. El id llega del navegador: uno que no es uuid, o de otro docente, no
     * existe (ADR-0014).
     *
     * @throws ModelNotFoundException<TeacherDepartment>
     */
    public function findAdscription(Teacher $teacher, string $id): TeacherDepartment
    {
        return KeyLookup::findOrFail(TeacherDepartment::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    /**
     * Una plaza sin borrar de ese docente; el id llega del navegador.
     *
     * @throws ModelNotFoundException<TeacherPosition>
     */
    public function findPosition(Teacher $teacher, string $id): TeacherPosition
    {
        return KeyLookup::findOrFail(TeacherPosition::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    /**
     * El perfil del docente (S17, supuesto por confirmar): las materias que puede dar, por clave, con su nivel y el
     * departamento de la materia (aunque esté borrado), quién la declaró y `taught_count`, cuántos grupos de ella ha
     * dado (el historial). Las preferentes primero, luego por clave; sin las quitadas.
     *
     * @return EloquentCollection<int, TeacherSubjectQualification>
     */
    public function profile(Teacher $teacher): EloquentCollection
    {
        $qualifications = $teacher->qualifications()
            ->with([
                'qualifier:id,name',
                'subject' => fn (BelongsTo $subject) => $subject->withTrashed()->select(['id', 'code', 'name', 'department_id'])->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name'])]),
            ])
            ->get();
        $taught = TeacherSubjectHistory::query()
            ->where('teacher_id', $teacher->getKey())
            ->whereIn('subject_id', $qualifications->pluck('subject_id')->all())
            ->selectRaw('subject_id, count(*) as total')
            ->groupBy('subject_id')
            ->pluck('total', 'subject_id');

        return $qualifications
            ->each(fn (TeacherSubjectQualification $qualification) => $qualification->setAttribute('taught_count', (int) ($taught[$qualification->subject_id] ?? 0)))
            ->sort(fn (TeacherSubjectQualification $a, TeacherSubjectQualification $b): int => [$a->level === QualificationLevel::Preferente ? 0 : 1, (string) $a->subject?->code, $a->id]
                <=> [$b->level === QualificationLevel::Preferente ? 0 : 1, (string) $b->subject?->code, $b->id])
            ->values();
    }

    /**
     * Las horas fuera del ITCJ del docente en `$term` (S16, supuesto por confirmar), por origen y sin las quitadas.
     *
     * @return EloquentCollection<int, TeacherExternalLoad>
     */
    public function externalLoads(Teacher $teacher, Term $term): EloquentCollection
    {
        return TeacherExternalLoad::query()
            ->where('teacher_id', $teacher->getKey())
            ->where('term_id', $term->getKey())
            ->orderBy('source')
            ->get();
    }

    /**
     * El historial del docente, del periodo más antiguo al más reciente (como el kárdex): por cada código de periodo
     * del SII con grupos o evaluaciones, su nombre, los grupos que impartió (materia y campus; una fila por grupo) y
     * sus evaluaciones por tipo. Cuatro consultas sin importar cuántos periodos sean.
     *
     * @return Collection<int, array{
     *     code: string,
     *     label: string,
     *     groups: Collection<int, TeacherSubjectHistory>,
     *     evaluations: Collection<int, TeacherEvaluation>
     * }>
     */
    public function history(Teacher $teacher): Collection
    {
        $groups = TeacherSubjectHistory::query()
            ->where('teacher_id', $teacher->getKey())
            ->with([
                'subject' => fn (BelongsTo $subject) => $subject->withTrashed()->select(['id', 'code', 'name']),
                'campus' => fn (BelongsTo $campus) => $campus->withTrashed()->select(['id', 'code']),
            ])
            ->get()
            ->sort(fn (TeacherSubjectHistory $a, TeacherSubjectHistory $b): int => [$a->term_code, (string) $a->subject?->code, $a->group_code, (string) $a->campus?->code, $a->id]
                <=> [$b->term_code, (string) $b->subject?->code, $b->group_code, (string) $b->campus?->code, $b->id])
            ->groupBy('term_code');
        $evaluations = TeacherEvaluation::query()->where('teacher_id', $teacher->getKey())->orderBy('kind')->get()->groupBy('term_code');
        $codes = $groups->keys()->merge($evaluations->keys())->unique()->sort()->values();
        $names = Term::query()->whereIn('code', $codes->all())->pluck('name', 'code');

        return $codes->map(fn (string $code): array => [
            'code' => $code,
            'label' => $names[$code] ?? $this->termLabel($code),
            'groups' => ($groups[$code] ?? collect())->values(),
            'evaluations' => ($evaluations[$code] ?? collect())->values(),
        ]);
    }

    /**
     * Las materias que se pueden declarar en el perfil: activas, de los departamentos dados (null es todos; un
     * arreglo vacío, ninguno), buscadas por clave o nombre, hasta `$limit`.
     *
     * @param  list<string>|null  $departments
     * @return Collection<int, Subject>
     */
    public function qualifiableSubjects(?array $departments, string $search = '', int $limit = 20): Collection
    {
        if ($departments === []) {
            return collect();
        }

        return Subject::query()
            ->with(['department' => fn (BelongsTo $department) => $department->withTrashed()->select(['id', 'code', 'name'])])
            ->where('is_active', true)
            ->when($departments !== null, fn ($query) => $query->whereIn('department_id', $departments))
            ->tap(fn ($query) => TextSearch::apply($query, ['code', 'name'], $search))
            ->orderBy('code')
            ->limit($limit)
            ->get(['id', 'code', 'name', 'department_id']);
    }

    /**
     * Una materia del perfil del docente, sin quitar; el id llega del navegador.
     *
     * @throws ModelNotFoundException<TeacherSubjectQualification>
     */
    public function findQualification(Teacher $teacher, string $id): TeacherSubjectQualification
    {
        return KeyLookup::findOrFail(TeacherSubjectQualification::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    /**
     * Unas horas fuera del ITCJ del docente, sin quitar; el id llega del navegador.
     *
     * @throws ModelNotFoundException<TeacherExternalLoad>
     */
    public function findExternalLoad(Teacher $teacher, string $id): TeacherExternalLoad
    {
        return KeyLookup::findOrFail(TeacherExternalLoad::query()->where('teacher_id', $teacher->getKey()), $id);
    }

    /**
     * El nombre de un código de periodo del SII que `terms` no tiene: `20241` es Ene-Jun 2024, `20242` Verano y
     * `20243` Ago-Dic.
     */
    private function termLabel(string $code): string
    {
        $season = match (substr($code, 4, 1)) {
            '1' => 'Ene-Jun',
            '2' => 'Verano',
            '3' => 'Ago-Dic',
            default => null,
        };

        return $season === null ? $code : $season.' '.substr($code, 0, 4);
    }

    private function status(TeacherPosition $position, string $today): string
    {
        return match (true) {
            $position->valid_from !== null && $position->valid_from->toDateString() > $today => 'por_empezar',
            $position->valid_to !== null && $position->valid_to->toDateString() < $today => 'vencida',
            default => 'vigente',
        };
    }

    private function covers(TeacherPosition $position, string $day): bool
    {
        return ($position->valid_from === null || $position->valid_from->toDateString() <= $day)
            && ($position->valid_to === null || $position->valid_to->toDateString() >= $day);
    }
}
