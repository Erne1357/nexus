<?php

namespace App\Modules\Faculty\Enums;

/**
 * `teacher_availability.kind`: no disponible o preferido.
 */
enum AvailabilityKind: string
{
    case Unavailable = 'unavailable';
    case Preferred = 'preferred';

    public function label(): string
    {
        return match ($this) {
            self::Unavailable => 'No disponible',
            self::Preferred => 'Preferido',
        };
    }

    /**
     * Su icono en la cuadrícula, la leyenda y los rangos (DESIGN.md, "Cuadrícula día x hora").
     */
    public function icon(): string
    {
        return match ($this) {
            self::Unavailable => 'o-no-symbol',
            self::Preferred => 'o-star',
        };
    }

    /**
     * El relleno de una celda o una muestra según su `data-kind` (el valor del tipo; sin él, disponible): rayas
     * grises para no disponible y un tinte de `success` para preferido. Van como variantes de `data-kind` porque el
     * editor cambia el tipo en el navegador sin volver a pintar la celda; las clases completas quedan escritas aquí
     * para que Tailwind las publique.
     */
    public static function fillClasses(): string
    {
        return 'bg-base-100 data-[kind=preferred]:bg-success/15 '
            .'data-[kind=unavailable]:bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_5px,color-mix(in_oklab,var(--color-neutral)_20%,var(--color-base-100))_5px_10px)]';
    }
}
