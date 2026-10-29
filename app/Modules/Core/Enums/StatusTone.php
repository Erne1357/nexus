<?php

namespace App\Modules\Core\Enums;

/**
 * Tono de una insignia de estado (DESIGN.md, "Insignias de estado"): decide las clases de daisyUI y
 * el icono por omisión de `<x-core::status-badge>`, para que un estado se vea igual en todos los
 * módulos. Cada módulo traduce sus estados a un tono; el texto lo pone el módulo.
 */
enum StatusTone: string
{
    /** Activo, Validado. */
    case Success = 'success';

    /** Publicado. */
    case SuccessSolid = 'success-solid';

    /** Inactivo. */
    case Neutral = 'neutral';

    /** Borrador. */
    case Draft = 'draft';

    /** En revisión, en cola. */
    case Info = 'info';

    /** Pendiente, Con advertencia. */
    case Warning = 'warning';

    /** Con error, Choque. */
    case Error = 'error';

    /** Periodo actual. */
    case Primary = 'primary';

    /**
     * Clases de daisyUI que se suman a `badge`. Van completas, sin armar, para que Tailwind las encuentre.
     */
    public function classes(): string
    {
        return match ($this) {
            self::Success => 'badge-success badge-soft',
            self::SuccessSolid => 'badge-success',
            self::Neutral => 'badge-neutral badge-soft',
            self::Draft => 'badge-dash',
            self::Info => 'badge-info badge-soft',
            self::Warning => 'badge-warning badge-soft',
            self::Error => 'badge-error badge-soft',
            self::Primary => 'badge-primary',
        };
    }

    /**
     * Icono de Heroicons por omisión; null si el tono no lleva icono.
     */
    public function icon(): ?string
    {
        return match ($this) {
            self::Success, self::SuccessSolid => 'o-check-circle',
            self::Neutral => 'o-minus-circle',
            self::Draft => 'o-pencil',
            self::Info => 'o-eye',
            self::Warning => 'o-clock',
            self::Error => 'o-x-circle',
            self::Primary => null,
        };
    }
}
