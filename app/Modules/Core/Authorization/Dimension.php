<?php

namespace App\Modules\Core\Authorization;

/**
 * Dimensiones del alcance de un nombramiento sobre las que se evalúa un permiso. La modalidad (AZ-S17) es la unidad de
 * la carga; el campus, el lugar físico.
 */
enum Dimension: string
{
    case Campus = 'campus';
    case Unit = 'unit';
    case Career = 'career';
    case Modality = 'modality';

    /**
     * Columna de `role_assignments` (y de los registros evaluados) que lleva la dimensión.
     */
    public function column(): string
    {
        return match ($this) {
            self::Campus => 'campus_id',
            self::Unit => 'org_unit_id',
            self::Career => 'career_id',
            self::Modality => 'modality_id',
        };
    }
}
