<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\Academic\Models\Department;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\OrgChartQuery;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Queries\TeacherWeeklyLoad;
use App\Modules\Faculty\Rules\BaseHours;
use App\Modules\Scheduling\Models\Group;
use App\Modules\Scheduling\Rules\TeacherHourLimit;
use Carbon\CarbonImmutable;

/**
 * La carga semanal de la ficha del docente (D-03): reutiliza «Mi carga» (MyLoadQuery) para el periodo en curso, que
 * es el que contiene hoy y sale de Nexus o, sin grupos, del SII, y para el siguiente periodo con grupos de Nexus del
 * docente. No usa el periodo activo de la credencial: los periodos se resuelven por fecha. Las horas son las de los
 * grupos que se ven; se miden contra las horas frente a grupo del docente con su banda (PL-01, HC-08; las de MyLoadQuery, con el respaldo del CSV de la selección) y, si pasa la banda, trae el aviso de HC-08 (`notice`).
 *
 * Cada semana de Nexus se parte por el departamento del grupo (tarea 1.59, B3): `departments` trae primero los de
 * adscripción del docente (`home`, el principal antes) y luego los demás con grupos, cada uno con su jefe vigente
 * (el titular vigente de la unidad, OrgChartQuery::titularNames), sus horas (las de la materia de cada grupo, como el encabezado de «Mi carga») y sus grupos, con o sin sesiones, y cada bloque de `days` lleva su
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
            'current' => $running === null ? null : $this->week($teacher, $running),
            'next' => $next === null ? null : $this->week($teacher, $next),
            'upcoming' => $upcoming?->name,
            'base_hours' => BaseHours::onDate($positions, $today),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function week(Teacher $teacher, Term $term): array
    {
        $load = $this->loads->forTeacher($teacher, $term);
        $hours = $load['hours'];
        $split = $load['source'] === 'nexus' && $load['groups'] !== [] ? $this->split($teacher, $load['days'], $load['groups']) : ['days' => $load['days'], 'departments' => []];

        return [
            'term' => $term->name,
            'code' => $term->code,
            'source' => $load['source'],
            'groups' => $load['groups'],
            'days' => $split['days'],
            'departments' => $split['departments'],
            'hours' => $hours['assigned'],
            'teaching' => $hours['teaching'],
            'band_from' => $hours['band_from'],
            'band_to' => $hours['band_to'],
            'honorarios_cap' => $hours['honorarios_cap'],
            'over_band' => $hours['over_band'],
            'notice' => TeacherHourLimit::notice([
                'teaching_hours' => $hours['teaching'], 'band_from' => $hours['band_from'], 'band_to' => $hours['band_to'],
                'honorarios_cap' => $hours['honorarios_cap'], 'assigned_hours' => $hours['assigned'], 'external_hours' => $hours['external'],
                'over_band' => $hours['over_band'], 'accepted' => $hours['accepted'],
            ]),
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

        // Las secciones salen de los grupos, no de los bloques: un grupo sin sesiones también cuenta, con las horas de su materia
        // como las cuenta «Mi carga» y su encabezado. Los bloques solo llevan el departamento y su color.
        $sections = [];

        foreach ($groups as $group) {
            $sections[$byGroup[$group['id']] ?? '']['groups'][$group['id']] = [
                'code' => $group['code'],
                'subject_code' => $group['subject_code'],
                'subject_name' => $group['subject_name'],
                'hours' => (int) $group['hours'],
                // PL-02: una tutoría cuenta 0 h en su carga, aunque ocupe su horario.
                'counts_teacher_hours' => (bool) ($group['counts_teacher_hours'] ?? true),
            ];
        }

        foreach ($days as $number => $day) {
            foreach ($day['blocks'] as $index => $block) {
                $department = $byGroup[$block['group_id']] ?? null;
                $days[$number]['blocks'][$index]['department_id'] = $department;
                $days[$number]['blocks'][$index]['tone'] = ($tones[$department] ?? count($tones)) % 12 + 1;
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
                'hours' => array_sum(array_column($groupsOf, 'hours')),
                'groups' => $groupsOf,
            ];
        }

        if (isset($sections[''])) {
            $groupsOf = array_values($sections['']['groups']);
            $departments[] = ['id' => null, 'name' => 'Sin departamento', 'home' => false, 'head' => null, 'tone' => count($tones) % 12 + 1, 'hours' => array_sum(array_column($groupsOf, 'hours')), 'groups' => $groupsOf];
        }

        return ['days' => $days, 'departments' => $departments];
    }
}
