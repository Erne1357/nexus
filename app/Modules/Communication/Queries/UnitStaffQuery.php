<?php

namespace App\Modules\Communication\Queries;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Core\Queries\TextSearch;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;

/**
 * Lecturas de «Personal» (CO-02): la unidad de Comunicación y Difusión, sus nombramientos de «Personal de área» vigentes
 * o por empezar y la búsqueda de una persona del personal para nombrarla. Del personal es quien tiene la cuenta activa y
 * no es solo alumno: sin un nombramiento vigente de alumno, o con otro puesto vigente además (la misma regla que la
 * lista de «Personas y puestos», AC-15). Así un alumno no sale en la búsqueda ni se elige con su id.
 */
final class UnitStaffQuery
{
    public function __construct(private readonly OrgTree $tree) {}

    /**
     * El puesto que maneja la pantalla: «Personal de área» (CO-02). Ningún otro se nombra ni se termina ahí.
     */
    public function role(): Role
    {
        return Role::Personal;
    }

    /**
     * El nombre del puesto sobre la unidad: «Personal del Departamento de Comunicación y Difusión» (Role::titleOn()).
     */
    public function title(OrgUnit $unit): string
    {
        return $this->role()->titleOn([['kind' => $unit->kind, 'code' => $unit->code, 'name' => $unit->name]]);
    }

    /**
     * La unidad de Comunicación y Difusión (OrgTree::COMMUNICATION).
     *
     * @throws ModelNotFoundException<OrgUnit> si no existe
     */
    public function unit(): OrgUnit
    {
        $id = $this->tree->idOf(OrgTree::COMMUNICATION);

        return OrgUnit::query()->whereKey($id)->firstOrFail();
    }

    /**
     * Los nombramientos de «Personal de área» de la unidad vigentes o por empezar, con su persona, por nombre.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function appointments(OrgUnit $unit): Collection
    {
        return $this->ofUnit($unit)
            ->with('user:id,name,username')
            ->get()
            ->sortBy(fn (RoleAssignment $assignment): string => mb_strtolower($assignment->user->name).'|'.$assignment->valid_from->toDateString())
            ->values();
    }

    /**
     * Un nombramiento de «Personal de área» de la unidad, vigente o por empezar; con un id de otro puesto o de otra unidad,
     * no existe.
     *
     * @throws ModelNotFoundException<RoleAssignment>
     */
    public function assignmentOf(OrgUnit $unit, string $id): RoleAssignment
    {
        return KeyLookup::findOrFail($this->ofUnit($unit)->with('user'), $id);
    }

    /**
     * Las personas del personal cuyo nombre o usuario contiene `$term`, por nombre; sin término, ninguna.
     *
     * @return Collection<int, User>
     */
    public function search(string $term, int $limit = 8): Collection
    {
        if (trim($term) === '') {
            return new Collection;
        }

        return $this->staff()
            ->tap(fn (Builder $query) => TextSearch::apply($query, ['name', 'username'], $term))
            ->orderBy('name')
            ->orderBy('id')
            ->limit($limit)
            ->get();
    }

    /**
     * La persona del personal con ese id; un alumno, una cuenta dada de baja o un id que no es uuid no existe.
     *
     * @throws ModelNotFoundException<User>
     */
    public function findStaffOrFail(string $id): User
    {
        return KeyLookup::findOrFail($this->staff(), $id);
    }

    /**
     * Las cuentas del personal (activas y que no son solo de alumno), para que otras pantallas de Communication las
     * busquen con la misma regla.
     *
     * @return Builder<User>
     */
    public function staffAccounts(): Builder
    {
        return $this->staff();
    }

    /**
     * @return Builder<RoleAssignment>
     */
    private function ofUnit(OrgUnit $unit): Builder
    {
        return RoleAssignment::query()
            ->notEnded()
            ->where('role', $this->role())
            ->where('org_unit_id', $unit->getKey());
    }

    /**
     * @return Builder<User>
     */
    private function staff(): Builder
    {
        return User::query()
            ->where('is_active', true)
            ->where(fn (Builder $staff) => $staff
                ->whereDoesntHave('roleAssignments', fn (Builder $assignments) => $assignments->current()->where('role', Role::Alumno))
                ->orWhereHas('roleAssignments', fn (Builder $assignments) => $assignments->current()->where('role', '<>', Role::Alumno)));
    }
}
