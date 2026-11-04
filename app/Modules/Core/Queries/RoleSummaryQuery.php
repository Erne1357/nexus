<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;

/**
 * El renglón de cada puesto en la credencial: «Coordinador de carrera · ISC, IGE (C2)». El nombre del puesto
 * (Role::label()) y, si sus nombramientos lo tienen, los nombres de sus unidades (se omite el que repite el del
 * puesto), los códigos de sus carreras (los pone ScopeLabels; sin ellos, «N carreras») y sus campus entre paréntesis.
 * Un puesto sin alcance es solo su nombre.
 */
final class RoleSummaryQuery
{
    public function __construct(private readonly ScopeLabels $labels) {}

    /**
     * Un renglón por puesto que tiene, en el orden del enum: valor del puesto → resumen.
     *
     * @return array<string, string>
     */
    public function forHeldRoles(Actor $context): array
    {
        $scopes = $context->heldScopes();
        $units = OrgUnit::query()->whereKey($scopes->pluck('org_unit_id')->filter()->unique()->all())->pluck('name', 'id');
        $campuses = Campus::query()->whereKey($scopes->pluck('campus_id')->filter()->unique()->all())->pluck('code', 'id');
        $careers = $this->labels->careers($scopes->pluck('career_id')->filter()->unique()->values()->all());

        $rows = [];

        foreach ($context->heldRoles() as $role) {
            $own = $scopes->where('role', $role);
            $rows[$role->value] = $this->summarize($role, $own->all(), $units->all(), $campuses->all(), $careers);
        }

        return $rows;
    }

    /**
     * @param  list<RoleAssignment>  $assignments
     * @param  array<string, string>  $units
     * @param  array<string, string>  $campuses
     * @param  array<string, string>  $careers
     */
    private function summarize(Role $role, array $assignments, array $units, array $campuses, array $careers): string
    {
        $unitNames = collect($assignments)->map(fn (RoleAssignment $a): ?string => $units[$a->org_unit_id] ?? null)
            ->filter(fn (?string $name): bool => $name !== null && $name !== $role->label())->unique()->sort()->values();
        $careerIds = collect($assignments)->pluck('career_id')->filter()->unique()->values();
        $careerCodes = $careerIds->map(fn (string $id): ?string => $careers[$id] ?? null);
        $careerText = $careerIds->isEmpty() ? null : ($careerCodes->contains(null)
            ? $careerIds->count().($careerIds->count() === 1 ? ' carrera' : ' carreras')
            : $careerCodes->sort()->implode(', '));
        $campusCodes = collect($assignments)->map(fn (RoleAssignment $a): ?string => $campuses[$a->campus_id] ?? null)
            ->filter()->unique()->sort()->values();

        $parts = array_filter([
            $unitNames->implode(', '),
            $careerText,
            $campusCodes->isEmpty() ? null : '('.$campusCodes->implode(', ').')',
        ]);

        return $role->label().($parts === [] ? '' : ' · '.implode(' ', $parts));
    }
}
