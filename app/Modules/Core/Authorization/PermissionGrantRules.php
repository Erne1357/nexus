<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;

/**
 * Qué permiso se le puede dar a qué puesto en el panel (roles editables, CF-01), con el motivo en español de cada
 * bloqueo:
 *
 * - CF-S1: el admin no se edita; tiene todo lo declarado menos adminDenies(), en código.
 * - CF-03: un candado (adminOnly()) no se da a otro puesto, ni suelto ni dentro de un rol. Un rol puede llevar
 *   cualquier otro permiso: lo demás se revisa al darlo a un puesto.
 * - CF-04 (confirmado el 14 de noviembre de 2026, «departamento o carrera»): un permiso de gestión solo se le da a un
 *   puesto que acota (Role::boundedDimensions()) el campus y la modalidad que el permiso declare y, si declara unidad
 *   o carrera, al menos una de las que declare: el departamento o la carrera bastan para acotarlo. Si no, valdría
 *   sin límite. El docente y el alumno no acotan ninguna dimensión: solo reciben permisos personales. Las concesiones
 *   de fábrica del puesto (grants()) siempre valen, porque se revisaron por PR; scopeProblem() responde sin esa
 *   excepción.
 *
 * Sin estado: solo lee el registro, que es inmutable. GrantTable la usa también al armar su mapa, así que nada de lo
 * que bloquea vale aunque llegue a la base por otro camino.
 */
final class PermissionGrantRules
{
    /**
     * Versión de estas reglas en la huella de GrantTable. Súbela cada vez que cambie lo que bloquean, para que un
     * despliegue que solo cambie las reglas no lea el mapa que armaron las anteriores.
     */
    public const int VERSION = 2;

    public function __construct(private readonly PermissionRegistry $registry) {}

    /**
     * CF-S1: el motivo por el que el puesto no se edita en el panel, o null.
     */
    public function positionProblem(Role $role): ?string
    {
        return $role === Role::Admin
            ? 'El puesto Administración no se edita: tiene todo lo declarado menos sus excepciones.'
            : null;
    }

    /**
     * CF-03: el motivo por el que el permiso no puede ir en un rol ni darse suelto (es candado), o null.
     */
    public function lockProblem(Permission $permission): ?string
    {
        return $this->registry->isAdminOnly($permission)
            ? "«{$permission->label()}» es solo del admin: no se da a otro puesto."
            : null;
    }

    /**
     * CF-S1, CF-03 y CF-04: el motivo por el que `$permission` no se le puede dar a `$role`, o null si se puede. Lo que
     * el código le concede de fábrica al puesto siempre se puede.
     */
    public function grantProblem(Role $role, Permission $permission): ?string
    {
        return $this->positionProblem($role)
            ?? $this->lockProblem($permission)
            ?? ($this->registry->grants($role, $permission) ? null : $this->scopeProblem($role, $permission));
    }

    /**
     * Si dar `$permission` a `$role` valdría en todo el Tec (confirmado el 15 de noviembre de 2026): es de gestión, no
     * declara ni unidad ni carrera, y el puesto no fija en su nombramiento ninguna de las dimensiones que declara (el
     * campus o la modalidad; el jefe y la Subdirección son generales en ellas). Se puede dar, pero el panel lo avisa
     * («Valdrá en todo el Tec»). Crear periodos, abrir la validación o importar lo son para cualquier puesto; abrir el
     * periodo en una modalidad, para el jefe y la Subdirección, no para el coordinador, que lleva modalidad.
     */
    public function isInstitutionWide(Role $role, Permission $permission): bool
    {
        if ($permission->isPersonal() || ! self::declaresNoUnitNorCareer($permission)) {
            return false;
        }

        $carried = [...$role->scopeColumns(), ...$role->optionalScopeColumns()];

        return ! array_any($permission->dimensions(), fn (Dimension $dimension): bool => in_array($dimension->column(), $carried, true));
    }

    /**
     * Si el permiso es de gestión y no declara ni unidad ni carrera: el que podría valer en todo el Tec según el puesto
     * (isInstitutionWide()).
     */
    public static function declaresNoUnitNorCareer(Permission $permission): bool
    {
        return ! $permission->isPersonal() && ! array_any(
            $permission->dimensions(),
            fn (Dimension $dimension): bool => in_array($dimension, [Dimension::Unit, Dimension::Career], true),
        );
    }

    /**
     * CF-04 sin la excepción de fábrica: el motivo por el que dar `$permission` a `$role` ampliaría su alcance, o null.
     * Un permiso personal nunca lo amplía: vale solo sobre lo propio. El campus y la modalidad que declare, cada uno; la
     * unidad y la carrera que declare, al menos una.
     */
    public function scopeProblem(Role $role, Permission $permission): ?string
    {
        if ($permission->isPersonal()) {
            return null;
        }

        $bounded = $role->boundedDimensions();

        if ($bounded === []) {
            return "«{$permission->label()}» no se da a {$role->listLabel()}: el puesto no lleva alcance y solo recibe permisos personales.";
        }

        $declared = $permission->dimensions();
        $open = array_values(array_filter(
            $declared,
            fn (Dimension $dimension): bool => in_array($dimension, [Dimension::Campus, Dimension::Modality], true) && ! in_array($dimension, $bounded, true),
        ));
        $scoping = array_values(array_filter($declared, fn (Dimension $dimension): bool => in_array($dimension, [Dimension::Unit, Dimension::Career], true)));

        if ($open === [] && ($scoping === [] || array_any($scoping, fn (Dimension $dimension): bool => in_array($dimension, $bounded, true)))) {
            return null;
        }

        $missing = $open !== [] ? [$open[0]] : $scoping;
        $nouns = array_map(fn (Dimension $dimension): array => match ($dimension) {
            Dimension::Campus => ['campus', 'todos los campus'],
            Dimension::Unit => ['departamento', 'todos los departamentos'],
            Dimension::Career => ['carrera', 'todas las carreras'],
            Dimension::Modality => ['modalidad', 'todas las modalidades'],
        }, $missing);

        return "«{$permission->label()}» no se da a {$role->listLabel()}: el puesto no lleva ".implode(' ni ', array_column($nouns, 0))
            .' y el permiso valdría en '.implode(' y ', array_column($nouns, 1)).'.';
    }
}
