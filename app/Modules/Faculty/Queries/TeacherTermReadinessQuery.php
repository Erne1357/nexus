<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Models\TeacherAvailability;
use App\Modules\Faculty\Models\TeacherExternalLoad;
use App\Modules\Faculty\Models\TeacherSubjectQualification;
use Illuminate\Database\Eloquent\Builder;

/**
 * Qué le falta a los docentes de un departamento en un periodo, para la validación de catálogos (CV6): si tienen
 * disponibilidad capturada (S12), cuántas materias activas del departamento tienen en su perfil, apto o preferente
 * (S17), sus horas fuera del ITCJ del periodo (S16) y sus horas de plaza (S1). Solo informa: no bloquea validar.
 *
 * «Capturada» es tener al menos un bloque guardado en el periodo; quien está disponible todo el periodo no guarda
 * ninguno y cuenta como sin capturar (supuesto por confirmar). Los conteos y los ids de «falta» son de los docentes
 * activos adscritos al departamento, sea o no la principal. Cada dato es una consulta, no una por docente.
 */
final class TeacherTermReadinessQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * El detalle de los docentes dados (los de la página visible).
     *
     * @param  list<string>  $teacherIds
     * @return array<string, array{has_availability: bool, subjects: int, external_hours: int, base_hours: int}> por id de docente
     */
    public function forTeachers(Department $department, Term $term, array $teacherIds): array
    {
        $available = TeacherAvailability::query()->forTerm($term)->whereIn('teacher_id', $teacherIds)->distinct()->pluck('teacher_id')->flip();
        $subjects = TeacherSubjectQualification::query()
            ->whereIn('teacher_id', $teacherIds)
            ->whereIn('subject_id', $this->activeSubjects($department))
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, count(*) as total')
            ->pluck('total', 'teacher_id');
        $external = TeacherExternalLoad::query()
            ->where('term_id', $term->getKey())
            ->whereIn('teacher_id', $teacherIds)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, sum(hours) as total')
            ->pluck('total', 'teacher_id');
        $base = $this->baseHours->forTerm($term, $teacherIds);

        return collect($teacherIds)->mapWithKeys(fn (string $id): array => [$id => [
            'has_availability' => $available->has($id),
            'subjects' => (int) ($subjects[$id] ?? 0),
            'external_hours' => (int) ($external[$id] ?? 0),
            'base_hours' => $base[$id] ?? 0,
        ]])->all();
    }

    /**
     * Cuántos docentes activos del departamento no tienen disponibilidad en el periodo ni perfil.
     *
     * @return array{no_availability: int, no_profile: int}
     */
    public function counts(Department $department, Term $term): array
    {
        return [
            'no_availability' => $this->missing($department, $term, 'disponibilidad')->count(),
            'no_profile' => $this->missing($department, $term, 'perfil')->count(),
        ];
    }

    /**
     * Los ids de los docentes activos a los que les falta `disponibilidad` o `perfil`; con otro valor, ninguno.
     *
     * @return list<string>
     */
    public function missingIds(Department $department, Term $term, string $missing): array
    {
        return in_array($missing, ['disponibilidad', 'perfil'], true)
            ? $this->missing($department, $term, $missing)->pluck('teachers.id')->all()
            : [];
    }

    /**
     * @return Builder<Teacher>
     */
    private function missing(Department $department, Term $term, string $missing): Builder
    {
        $has = $missing === 'disponibilidad'
            ? TeacherAvailability::query()->forTerm($term)->select('teacher_id')
            : TeacherSubjectQualification::query()->whereIn('subject_id', $this->activeSubjects($department))->select('teacher_id');

        return Teacher::query()
            ->where('teachers.is_active', true)
            ->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where('department_id', $department->getKey()))
            ->whereNotIn('teachers.id', $has);
    }

    /**
     * @return Builder<Subject>
     */
    private function activeSubjects(Department $department): Builder
    {
        return Subject::query()->where('department_id', $department->getKey())->where('is_active', true)->select('id');
    }
}
