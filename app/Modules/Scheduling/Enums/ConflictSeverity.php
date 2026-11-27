<?php

namespace App\Modules\Scheduling\Enums;

/**
 * Gravedad de un choque (docs/dominio/reglas-de-negocio.md): las restricciones duras (HC) bloquean el cierre de la
 * carga (HC-15); las blandas (SC) solo se reportan en R1.
 */
enum ConflictSeverity: string
{
    case Hard = 'hard';
    case Soft = 'soft';

    /**
     * Nombre para la interfaz.
     */
    public function label(): string
    {
        return match ($this) {
            self::Hard => 'Choque duro',
            self::Soft => 'Aviso',
        };
    }
}
