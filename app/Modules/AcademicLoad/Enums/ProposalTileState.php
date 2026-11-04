<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * El estado de un mosaico de la retícula de la propuesta de carrera y de su leyenda (DESIGN.md, "Retícula de la
 * propuesta de carrera"; maqueta C, elegida por el usuario el 4 de noviembre de 2026). Lo que decidió el
 * departamento de la materia manda sobre sus grupos: regresada, luego aceptada; si no ha decidido, una materia
 * sin grupos se marca sin grupos y las demás van neutras. El relleno y el icono van juntos, porque el color nunca
 * va solo; el valor es el `data-tone` del mosaico.
 */
enum ProposalTileState: string
{
    case WithGroups = 'pending';
    case NoGroups = 'none';
    case Accepted = 'accepted';
    case Returned = 'returned';

    /**
     * El estado de una materia: `$part` es el de la parte de su departamento (`returned`, `accepted` o `pending`).
     */
    public static function of(string $part, int $requestedGroups): self
    {
        return match (true) {
            $part === 'returned' => self::Returned,
            $part === 'accepted' => self::Accepted,
            $requestedGroups === 0 => self::NoGroups,
            default => self::WithGroups,
        };
    }

    /**
     * El borde, el relleno y el color del texto del mosaico y de su muestra en la leyenda.
     */
    public function tileClasses(): string
    {
        return match ($this) {
            self::WithGroups => 'border-base-content/30 bg-base-100 text-base-content',
            self::NoGroups => 'border-dashed border-warning bg-warning/10 text-warning',
            self::Accepted => 'border-success/40 bg-success/10 text-success',
            self::Returned => 'border-error/40 bg-error/10 text-error',
        };
    }

    /**
     * El icono del mosaico y de la leyenda; «con grupos» no lleva.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::WithGroups => null,
            self::NoGroups => 'o-minus-circle',
            self::Accepted => 'o-lock-closed',
            self::Returned => 'o-arrow-uturn-left',
        };
    }

    /**
     * El nombre en la leyenda de la retícula.
     */
    public function label(): string
    {
        return match ($this) {
            self::WithGroups => 'Con grupos',
            self::NoGroups => 'Sin grupos',
            self::Accepted => 'Aceptada por el departamento',
            self::Returned => 'Regresada por el departamento',
        };
    }
}
