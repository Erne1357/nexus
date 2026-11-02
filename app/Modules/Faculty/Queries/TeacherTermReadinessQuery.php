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
 * Qué le falta a cada docente de un departamento en un periodo, para la validación de catálogos (CV6): si tiene
 * disponibilidad capturada (S12), cuántas materias del departamento tiene en su perfil, apto o preferente (S17),
 * sus horas fuera del ITCJ del periodo (S16) y sus horas de plaza (S1). Solo informa: no bloquea validar.
 *
 * «Capturada» es tener al menos un bloque guardado en el periodo; quien está disponible todo el periodo no guarda
 * ninguno y cuenta como sin capturar (supuesto por confirmar). Los docentes son los adscritos al departamento, sea
 * o no la principal. Una consulta por dato, no una por docente.
 */
final class TeacherTermReadinessQuery
{
    public function __construct(private readonly TeacherBaseHoursQuery $baseHours) {}

    /**
     * @return array<string, array{is_active: bool, has_availability: bool, subjects: int, external_hours: int, base_hours: int}> por id de docente
     */
    public function forDepartment(Department $department, Term $term): array
    {
        $teachers = Teacher::query()
            ->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where('department_id', $department->getKey()))
            ->orderBy('employee_number')
            ->pluck('is_active', 'id');

        $ids = $teachers->keys()->all();

        $available = TeacherAvailability::query()->forTerm($term)->whereIn('teacher_id', $ids)->distinct()->pluck('teacher_id')->flip();
        $subjects = TeacherSubjectQualification::query()
            ->whereIn('teacher_id', $ids)
            ->whereIn('subject_id', Subject::query()->where('department_id', $department->getKey())->select('id'))
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, count(*) as total')
            ->pluck('total', 'teacher_id');
        $external = TeacherExternalLoad::query()
            ->where('term_id', $term->getKey())
            ->whereIn('teacher_id', $ids)
            ->groupBy('teacher_id')
            ->selectRaw('teacher_id, sum(hours) as total')
            ->pluck('total', 'teacher_id');
        $base = $this->baseHours->forTerm($term, $ids);

        return $teachers->map(fn ($active, $id): array => [
            'is_active' => (bool) $active,
            'has_availability' => $available->has($id),
            'subjects' => (int) ($subjects[$id] ?? 0),
            'external_hours' => (int) ($external[$id] ?? 0),
            'base_hours' => $base[$id] ?? 0,
        ])->all();
    }

    /**
     * Los docentes activos sin disponibilidad y sin perfil.
     *
     * @param  array<string, array{is_active: bool, has_availability: bool, subjects: int, external_hours: int, base_hours: int}>  $rows
     * @return array{no_availability: int, no_profile: int}
     */
    public function counts(array $rows): array
    {
        return [
            'no_availability' => count($this->missingIds($rows, 'disponibilidad')),
            'no_profile' => count($this->missingIds($rows, 'perfil')),
        ];
    }

    /**
     * Los ids de los docentes activos a los que les falta `disponibilidad` o `perfil`; con otro valor, ninguno.
     *
     * @param  array<string, array{is_active: bool, has_availability: bool, subjects: int, external_hours: int, base_hours: int}>  $rows
     * @return list<string>
     */
    public function missingIds(array $rows, string $missing): array
    {
        return array_values(array_keys(array_filter($rows, fn (array $row): bool => $row['is_active'] && match ($missing) {
            'disponibilidad' => ! $row['has_availability'],
            'perfil' => $row['subjects'] === 0,
            default => false,
        })));
    }
}
