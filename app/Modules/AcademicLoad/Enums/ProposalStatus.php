<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Estado de una propuesta de carrera (docs/dominio/modelo-de-datos.md, `career_proposals`; reglas-de-negocio.md,
 * "Propuesta de carrera"): nace en borrador, el coordinador la envía, y queda aceptada cuando todos los
 * departamentos involucrados aceptaron su parte o regresada si uno la regresa.
 */
enum ProposalStatus: string
{
    case Draft = 'draft';
    case Submitted = 'submitted';
    case Returned = 'returned';
    case Accepted = 'accepted';

    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Borrador',
            self::Submitted => 'Enviada',
            self::Returned => 'Regresada',
            self::Accepted => 'Aceptada',
        };
    }

    /**
     * Tono de la insignia de estado (DESIGN.md, "Insignias de estado"): el borrador en su tono; la enviada en
     * info, porque la están revisando; la regresada en advertencia, porque el coordinador tiene que corregirla,
     * y la aceptada en éxito.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Draft => StatusTone::Draft,
            self::Submitted => StatusTone::Info,
            self::Returned => StatusTone::Error,
            self::Accepted => StatusTone::Success,
        };
    }

    /**
     * Si el coordinador la puede cambiar y enviar: en borrador y regresada. Enviada queda de solo lectura
     * (confirmado el 2 de noviembre de 2026), y aceptada ya no cambia.
     */
    public function isEditable(): bool
    {
        return $this === self::Draft || $this === self::Returned;
    }
}
