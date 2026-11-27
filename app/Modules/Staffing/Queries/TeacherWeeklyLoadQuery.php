<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\OrgChartQuery;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherWeeklyLoad;
use App\Modules\Faculty\Rules\BaseHours;
use App\Modules\Scheduling\Models\Group;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;

/**
 * La carga semanal de la ficha del docente (D-03): reutiliza «Mi carga» (MyLoadQuery) para el periodo en curso, que
 * es el que contiene hoy y sale de Nexus o, sin grupos, del SII, y para el siguiente periodo con grupos de Nexus del
 * docente. No usa el periodo activo de la credencial: los periodos se resuelven por fecha. Las horas son las de los
 * grupos que se ven; la base es la de las plazas vigentes hoy (el en curso) o al inicio del periodo (el siguiente).
 *
 * Cada semana de Nexus se parte por el departamento del grupo (tarea 1.59, B3): `departments` trae primero los de
 * adscripción del docente (`home`, el principal antes) y luego los demás con grupos, cada uno con su jefe vigente
 * (el titular vigente de la unidad, OrgChartQuery::titularNames), sus horas y sus grupos, y cada bloque de `days` lleva su
 * `department_id` y su `tone` (1 a 12, el color del departamento). La semana que sale del SII no sabe el departamento
 * del grupo en Nexus: lleva `departments` vacío y se muestra sin partir (supuesto por confirmar).
 */
final class TeacherWeeklyLoadQuery implements TeacherWeeklyLoad
{
    public function __construct(private readonly MyLoadQuery $loads, private readonly OrgChartQuery $orgChart) {}

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
        $split = $load['source'] === 'nexus' && $load['groups'] !== [] ? $this->split($teacher, $load['days'], $load['groups']) : ['days' => $load['days'], 'departments' => []];

        return [
            'term' => $term->name,
            'code' => $term->code,
            'source' => $load['source'],
            'groups' => $load['groups'],
            'days' => $split['days'],
            'departments' => $split['departments'],
            'hours' => $load['hours']['assigned'],
            'base' => BaseHours::onDate($positions, $day),
            'tolerance' => $load['hours']['tolerance'],
        ];
    }

    /**
     * Parte los grupos de la semana por departamento y marca cada bloque con el suyo.
     *
     * @param  array<int, array{name: string, short: string, hours: int, blocks: list<array<string, mixed>>}>  $days
     * @param  list<array<string, mixed>>  $groups
     * @return array{days: array<int, array<string, mixed>>, departments: list<array<string, mixed>>}
     */
    private function split(Teacher $teacher, array $days, array $groups): array
    {
        $ids = array_column($groups, 'id');
        $byGroup = Group::query()->whereIn('id', $ids)->pluck('department_id', 'id')->all();
        $homeIds = $teacher->adscriptions()->orderByDesc('is_primary')->pluck('department_id')->all();
        $used = array_values(array_unique(array_filter(array_map(fn (string $id): ?string => $byGroup[$id] ?? null, $ids))));
        $order = [...$homeIds, ...array_values(array_diff($used, $homeIds))];
        $names = Department::query()->whereIn('id', $order)->pluck('name', 'id')->all();
        $heads = $this->orgChart->titularNames($used);
        $tones = array_flip($order);

        $sections = [];

        foreach ($days as $number => $day) {
            foreach ($day['blocks'] as $index => $block) {
                $department = $byGroup[$block['group_id']] ?? null;
                $days[$number]['blocks'][$index]['department_id'] = $department;
                $days[$number]['blocks'][$index]['tone'] = ($tones[$department] ?? count($tones)) % 12 + 1;
                $hours = ($block['end'] - $block['start']) / 60;
                $group = &$sections[$department ?? '']['groups'][$block['group_id']];
                $group ??= ['code' => $block['group_code'], 'subject_code' => $block['subject_code'], 'subject_name' => $block['subject_name'], 'hours' => 0.0];
                $group['hours'] += $hours;
                unset($group);
            }
        }

        $departments = [];

        foreach ($order as $id) {
            $groupsOf = array_values($sections[$id]['groups'] ?? []);

            if ($groupsOf === [] && ! in_array($id, $homeIds, true)) {
                continue;
            }

            $departments[] = [
                'id' => $id,
                'name' => $names[$id] ?? '',
                'home' => in_array($id, $homeIds, true),
                'head' => $heads[$id] ?? null,
                'tone' => $tones[$id] % 12 + 1,
                'hours' => (int) round(array_sum(array_column($groupsOf, 'hours'))),
                'groups' => array_map(fn (array $group): array => [...$group, 'hours' => (int) round($group['hours'])], $groupsOf),
            ];
        }

        if (isset($sections[''])) {
            $groupsOf = array_values($sections['']['groups']);
            $departments[] = ['id' => null, 'name' => 'Sin departamento', 'home' => false, 'head' => null, 'tone' => count($tones) % 12 + 1, 'hours' => (int) round(array_sum(array_column($groupsOf, 'hours'))), 'groups' => array_map(fn (array $group): array => [...$group, 'hours' => (int) round($group['hours'])], $groupsOf)];
        }

        return ['days' => $days, 'departments' => $departments];
    }
}
