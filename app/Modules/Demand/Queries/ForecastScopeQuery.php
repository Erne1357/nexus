<?php

namespace App\Modules\Demand\Queries;

use App\Modules\Academic\Enums\SubjectKind;
use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\CareerCampus;
use App\Modules\Academic\Models\StudyPlanSubject;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use Illuminate\Support\Collection;

/**
 * Lo que cubre la proyección de demanda: qué carreras, en qué columnas (campus) y con qué materias. Replica la
 * selección de la propuesta de carrera (HC-13; confirmado el 2 de noviembre de 2026: mismas materias y mismas columnas),
 * para que las filas de la proyección coincidan una a una con las de la propuesta. Demand no puede depender de
 * AcademicLoad, por eso la lógica se reproduce aquí y no se importa.
 *
 * - careers(): carreras activas y sin borrar con al menos una oferta (`career_campus`) activa y sin borrar en un campus
 *   activo y sin borrar, por clave.
 * - campusesFor(): las columnas de una carrera en una modalidad: los campus activos donde se ofrece, por clave, si la
 *   modalidad usa salones; si no, una sola columna sin campus (`null`).
 * - subjectsFor(): las materias activas de la retícula de los planes activos de la carrera, una vez cada una (la del
 *   primer plan por clave), sin residencia, servicio social ni complementarias y sin materias sin horas de clase.
 */
final class ForecastScopeQuery
{
    /**
     * Los tipos de materia que no entran a la proyección (igual que la propuesta).
     *
     * @var list<string>
     */
    private const array OUTSIDE_PROPOSAL = [SubjectKind::Residencia->value, SubjectKind::ServicioSocial->value, SubjectKind::Complementaria->value];

    /**
     * @return Collection<int, Career>
     */
    public function careers(): Collection
    {
        return Career::query()
            ->where('is_active', true)
            ->whereIn('id', CareerCampus::query()
                ->select('career_id')
                ->where('is_active', true)
                ->whereIn('campus_id', Campus::query()->select('id')->where('is_active', true)))
            ->orderBy('code')
            ->get();
    }

    /**
     * @return list<Campus|null>
     */
    public function campusesFor(Modality $modality, Career $career): array
    {
        if (! $modality->profile->usesRooms) {
            return [null];
        }

        return Campus::query()
            ->whereIn('id', CareerCampus::query()->select('campus_id')->where('career_id', $career->getKey())->where('is_active', true))
            ->where('is_active', true)
            ->orderBy('code')
            ->get()
            ->values()
            ->all();
    }

    /**
     * @return Collection<int, object{subject_id: string, study_plan_id: string, semester: int, needs_lab: bool, department_id: ?string, is_specialty: bool}>
     */
    public function subjectsFor(Career $career): Collection
    {
        return StudyPlanSubject::query()
            ->join('study_plans', 'study_plans.id', '=', 'study_plan_subjects.study_plan_id')
            ->join('subjects', 'subjects.id', '=', 'study_plan_subjects.subject_id')
            ->where('study_plans.career_id', $career->getKey())
            ->where('study_plans.is_active', true)
            ->whereNull('study_plans.deleted_at')
            ->where('subjects.is_active', true)
            ->whereNull('subjects.deleted_at')
            ->where(fn ($query) => $query->whereNull('subjects.kind')->orWhereNotIn('subjects.kind', self::OUTSIDE_PROPOSAL))
            ->whereRaw('subjects.theory_hours + subjects.practice_hours > 0')
            ->orderBy('study_plans.code')
            ->orderBy('study_plan_subjects.semester')
            ->orderBy('subjects.code')
            ->toBase()
            ->get([
                'study_plan_subjects.subject_id',
                'study_plan_subjects.study_plan_id',
                'study_plan_subjects.semester',
                'subjects.needs_lab',
                'subjects.department_id',
                'study_plan_subjects.specialty_id',
            ])
            ->unique('subject_id')
            ->map(fn (object $row): object => (object) [
                'subject_id' => $row->subject_id,
                'study_plan_id' => $row->study_plan_id,
                'semester' => (int) $row->semester,
                'needs_lab' => (bool) $row->needs_lab,
                'department_id' => $row->department_id,
                'is_specialty' => $row->specialty_id !== null,
            ])
            ->values();
    }
}
