<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\OrgUnitKind;
use LogicException;

/**
 * A qué unidades aplica un permiso (D20, confirmado el 5 de noviembre de 2026: lo que puede un jefe depende de su
 * unidad): tipos de unidad y claves de unidades estructurales. Lo declara cada permiso en Permission::appliesTo(); un
 * nombramiento con unidad aporta el permiso solo si su unidad, o una de sus descendientes, cumple (OrgTree::fits()).
 *
 * Una unidad cumple si su tipo es uno de los declarados, o si no es un departamento académico y su clave es una de
 * las declaradas: la clave es única solo entre las unidades estructurales (un departamento académico puede repetirla),
 * igual que en OrgTree::idOf(). Inmutable.
 */
final readonly class UnitApplicability
{
    /**
     * @param  list<OrgUnitKind>  $kinds
     * @param  list<string>  $codes
     */
    private function __construct(public array $kinds, public array $codes) {}

    /**
     * @param  list<OrgUnitKind>  $kinds
     * @param  list<string>  $codes
     *
     * @throws LogicException sin ningún tipo ni clave, o con una clave vacía: un permiso que no aplicara a ninguna
     *                        unidad se declara sin concederlo
     */
    public static function of(array $kinds = [], array $codes = []): self
    {
        if ($kinds === [] && $codes === []) {
            throw new LogicException('Un permiso que declara a qué unidades aplica nombra al menos un tipo o una clave.');
        }

        if (in_array('', $codes, true)) {
            throw new LogicException('La clave de una unidad no puede ser una cadena vacía.');
        }

        $byValue = [];

        foreach ($kinds as $kind) {
            $byValue[$kind->value] = $kind;
        }

        ksort($byValue);
        $kinds = array_values($byValue);
        $codes = array_values(array_unique($codes));
        sort($codes);

        return new self($kinds, $codes);
    }

    /**
     * Lo académico (D20): los departamentos académicos y la Subdirección Académica, por su clave, así que la
     * Subdirección cumple aunque todavía no tenga departamentos debajo.
     */
    public static function academic(): self
    {
        return self::of([OrgUnitKind::DepartamentoAcademico], [OrgTree::ACADEMIC]);
    }

    public function matches(OrgUnitKind $kind, string $code): bool
    {
        return in_array($kind, $this->kinds, true)
            || ($kind !== OrgUnitKind::DepartamentoAcademico && in_array($code, $this->codes, true));
    }

    /**
     * Texto estable que la identifica (`kinds=departamento_academico;codes=SUB-ACADEMICA`): agrupa los permisos en la
     * matriz congelada y memoriza OrgTree::fits().
     */
    public function key(): string
    {
        return implode(';', array_filter([
            $this->kinds === [] ? null : 'kinds='.implode(',', array_map(fn (OrgUnitKind $kind): string => $kind->value, $this->kinds)),
            $this->codes === [] ? null : 'codes='.implode(',', $this->codes),
        ]));
    }
}
