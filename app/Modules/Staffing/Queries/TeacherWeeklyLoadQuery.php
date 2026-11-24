<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Core\Models\Term;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherWeeklyLoad;
use App\Modules\Faculty\Rules\BaseHours;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La carga semanal de la ficha del docente (D-03): reutiliza «Mi carga» (MyLoadQuery) para el periodo en curso, que
 * es el que contiene hoy y sale de Nexus o, sin grupos, del SII, y para el siguiente periodo con grupos de Nexus del
 * docente. No usa el periodo activo de la credencial: los periodos se resuelven por fecha. Las horas son las de los
 * grupos que se ven; la base es la de las plazas vigentes hoy (el en curso) o al inicio del periodo (el siguiente).
 */
final class TeacherWeeklyLoadQuery implements TeacherWeeklyLoad
{
    public function __construct(private readonly MyLoadQuery $loads) {}

    public function forTeacher(Teacher $teacher): array
    {
        $today = CarbonImmutable::now('America/Ciudad_Juarez');
        $running = Term::query()->containing($today)->orderByDesc('code')->first();
        $positions = $teacher->positions()->get()->map(fn ($position): array => [
            'base_hours' => $position->base_hours,
            'valid_from' => $position->valid_from?->toDateString(),
            'valid_to' => $position->valid_to?->toDateString(),
        ]);

        $after = $running?->ends_on ?? $today;
        $later = Term::query()->whereDate('starts_on', '>', $after)->orderBy('starts_on')->get();
        $next = $later->first(fn (Term $term): bool => $this->loads->hasNexusGroups($teacher, $term));
        $upcoming = $later->first();

        return [
            'current' => $running === null ? null : $this->week($teacher, $running, $positions->all(), $today),
            'next' => $next === null ? null : $this->week($teacher, $next, $positions->all(), $next->starts_on),
            'upcoming' => $upcoming?->name,
            'base_hours' => BaseHours::onDate($positions, $today),
        ];
    }

    /**
     * @param  list<array{base_hours: int, valid_from: ?string, valid_to: ?string}>  $positions
     * @return array<string, mixed>
     */
    private function week(Teacher $teacher, Term $term, array $positions, CarbonInterface $day): array
    {
        $load = $this->loads->forTeacher($teacher, $term);

        return [
            'term' => $term->name,
            'code' => $term->code,
            'source' => $load['source'],
            'groups' => $load['groups'],
            'days' => $load['days'],
            'hours' => $load['hours']['assigned'],
            'base' => BaseHours::onDate($positions, $day),
            'tolerance' => $load['hours']['tolerance'],
        ];
    }
}
