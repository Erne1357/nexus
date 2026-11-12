<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;

/**
 * Nombra el alcance de los nombramientos para las pantallas, los avisos y los errores: una consulta por dimensión
 * (unidades, campus, modalidades y, por ScopeLabels, carreras), con borrados, porque un nombramiento viejo sigue
 * nombrando lo que se dio de baja. El alcance va de lo académico a lo físico: unidad o carrera, modalidad y campus
 * (AZ-S17). Un puesto con el campus opcional y sin él dice «Todos los campus».
 */
final class AssignmentLabels
{
    public function __construct(private readonly ScopeLabels $labels) {}

    /**
     * @param  iterable<RoleAssignment>  $assignments
     * @return array<string, AssignmentLabel> por id del nombramiento
     */
    public function describe(iterable $assignments): array
    {
        $list = [];

        foreach ($assignments as $assignment) {
            $list[] = $assignment;
        }

        $units = $this->unitsOf($list);
        $campuses = $this->idsOf($list, 'campus_id') === [] ? [] : Campus::withTrashed()->whereKey($this->idsOf($list, 'campus_id'))->pluck('name', 'id')->all();
        $modalities = $this->idsOf($list, 'modality_id') === [] ? [] : Modality::withTrashed()->whereKey($this->idsOf($list, 'modality_id'))->pluck('name', 'id')->all();
        $careers = $this->labels->careers($this->idsOf($list, 'career_id'));
        $careerNames = $this->labels->careerNames($this->idsOf($list, 'career_id'));

        $described = [];

        foreach ($list as $assignment) {
            $described[$assignment->getKey()] = $this->label($assignment, $units, $campuses, $modalities, $careers, $careerNames);
        }

        return $described;
    }

    public function one(RoleAssignment $assignment): AssignmentLabel
    {
        return $this->describe([$assignment])[$assignment->getKey()];
    }

    /**
     * @param  list<RoleAssignment>  $list
     * @return array<string, array{name: string, kind: OrgUnitKind, code: string}>
     */
    private function unitsOf(array $list): array
    {
        $ids = $this->idsOf($list, 'org_unit_id');

        if ($ids === []) {
            return [];
        }

        $units = [];

        foreach (OrgUnit::withTrashed()->whereKey($ids)->get(['id', 'name', 'kind', 'code']) as $unit) {
            $units[$unit->id] = ['name' => $unit->name, 'kind' => $unit->kind, 'code' => $unit->code];
        }

        return $units;
    }

    /**
     * @param  list<RoleAssignment>  $list
     * @return list<string>
     */
    private function idsOf(array $list, string $column): array
    {
        return collect($list)->pluck($column)->filter()->unique()->values()->all();
    }

    /**
     * El título es el nombre del puesto para quien lo ocupa sobre su unidad (Role::titleOn(): el jefe de Servicios
     * Escolares, «Jefe del Departamento de Servicios Escolares», AZ-02); el resumen omite la unidad si el título ya la
     * nombra.
     *
     * @param  array<string, array{name: string, kind: OrgUnitKind, code: string}>  $units
     * @param  array<string, string>  $campuses
     * @param  array<string, string>  $modalities
     * @param  array<string, string>  $careers
     * @param  array<string, string>  $careerNames
     */
    private function label(RoleAssignment $assignment, array $units, array $campuses, array $modalities, array $careers, array $careerNames = []): AssignmentLabel
    {
        $unit = $assignment->org_unit_id === null ? null : ($units[$assignment->org_unit_id] ?? null);
        $title = $assignment->role->titleOn($unit === null ? [] : [$unit]);
        $chips = [];

        if ($unit !== null) {
            $chips[] = [
                'label' => $unit['kind'] === OrgUnitKind::DepartamentoAcademico ? 'Depto.' : 'Unidad',
                'value' => $unit['name'],
            ];
        }

        if ($assignment->career_id !== null && isset($careers[$assignment->career_id])) {
            $chips[] = ['label' => 'Carrera', 'value' => $careers[$assignment->career_id]];
        }

        if ($assignment->modality_id !== null && isset($modalities[$assignment->modality_id])) {
            $chips[] = ['label' => 'Modalidad', 'value' => $modalities[$assignment->modality_id]];
        }

        if ($assignment->campus_id !== null && isset($campuses[$assignment->campus_id])) {
            $chips[] = ['label' => 'Campus', 'value' => $campuses[$assignment->campus_id]];
        } elseif ($assignment->campus_id === null && in_array('campus_id', $assignment->role->optionalScopeColumns(), true)) {
            $chips[] = ['label' => 'Campus', 'value' => 'Todos los campus'];
        }

        $values = array_values(array_filter(array_column($chips, 'value'), fn (string $value): bool => $value !== $title && ($unit === null || $value !== $unit['name'] || ! str_contains($title, $value))));

        $chipValue = fn (string $label): ?string => collect($chips)->firstWhere('label', $label)['value'] ?? null;

        return new AssignmentLabel(
            $title,
            $chips,
            implode(' · ', [$title, ...$values]),
            careerCode: $chipValue('Carrera'),
            careerName: $assignment->career_id === null ? null : ($careerNames[$assignment->career_id] ?? null),
            unitName: $unit['name'] ?? null,
            modalityName: $chipValue('Modalidad'),
            campusName: $chipValue('Campus'),
        );
    }
}
