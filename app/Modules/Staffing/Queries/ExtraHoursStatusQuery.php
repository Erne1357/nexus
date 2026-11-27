<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Authorization\NoHonorariosAppointments;
use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Staffing\Enums\ExtraHoursDecision;
use App\Modules\Staffing\Enums\ExtraHoursState;
use App\Modules\Staffing\Models\TeacherExtraHoursDecision;
use App\Modules\Staffing\Models\TeacherTermLoad;

/**
 * PL-04 (tarea 1.81): el estado de las horas extra de cada docente en un periodo, en tres consultas sin importar cuántos
 * sean: sus horas extra (`teacher_term_loads.extra_hours`, recalculadas con la carga), la decisión de su jefe y quién tiene
 * un puesto sin honorarios (NoHonorariosAppointments). Reglas:
 * - sin horas extra, no hay estado que decir;
 * - quien tiene jefatura, subdirección o dirección: «jefatura», sin decisión posible (confirmado el 27 de noviembre de 2026);
 * - sin decisión, o con una que cubrió menos horas de las que ya tiene (la decisión se reabre, supuesto por confirmar):
 *   «por autorizar»; si no, la decisión.
 */
final class ExtraHoursStatusQuery
{
    public function __construct(private readonly NoHonorariosAppointments $noHonorarios) {}

    /**
     * @param  list<string>  $teacherIds
     * @return array<string, ExtraHoursStatus> por id de docente; todos los pedidos aparecen
     */
    public function forTerm(Term $term, array $teacherIds): array
    {
        if ($teacherIds === []) {
            return [];
        }

        $extra = TeacherTermLoad::query()->where('term_id', $term->getKey())->whereIn('teacher_id', $teacherIds)->pluck('extra_hours', 'teacher_id');
        $decisions = TeacherExtraHoursDecision::query()->where('term_id', $term->getKey())->whereIn('teacher_id', $teacherIds)->get()->keyBy('teacher_id');
        $withExtra = array_values(array_filter($teacherIds, fn (string $id): bool => (int) ($extra[$id] ?? 0) > 0));
        $userIds = Teacher::query()->whereIn('id', $withExtra)->whereNotNull('user_id')->pluck('user_id', 'id');
        $restricted = array_flip($this->noHonorarios->among($userIds->values()->all(), now()));
        $statuses = [];

        foreach ($teacherIds as $id) {
            $hours = (int) ($extra[$id] ?? 0);

            if ($hours <= 0) {
                $statuses[$id] = ExtraHoursStatus::none();

                continue;
            }

            $decision = $decisions->get($id);

            $statuses[$id] = match (true) {
                isset($restricted[$userIds[$id] ?? '']) => new ExtraHoursStatus($hours, ExtraHoursState::Jefatura),
                $decision === null || $hours > $decision->hours => new ExtraHoursStatus($hours, ExtraHoursState::Pending, $decision?->hours),
                $decision->decision === ExtraHoursDecision::Authorized => new ExtraHoursStatus($hours, ExtraHoursState::Authorized, $decision->hours),
                default => new ExtraHoursStatus($hours, ExtraHoursState::Unauthorized, $decision->hours),
            };
        }

        return $statuses;
    }

    public function forTeacher(Term $term, string $teacherId): ExtraHoursStatus
    {
        return $this->forTerm($term, [$teacherId])[$teacherId];
    }
}
