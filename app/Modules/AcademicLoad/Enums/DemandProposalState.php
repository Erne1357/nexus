<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Cómo está la propuesta de una carrera para el tablero «Demanda por materia» (tarea 5.7): enviada, aceptada o regresada
 * (lo que pidió cuenta), en captura (un borrador) o sin propuesta. Una carrera que no ha enviado cuenta lo sugerido por
 * la proyección en los totales del tablero, y su borrador todavía no (supuesto por confirmar, maqueta C del 17 de
 * noviembre de 2026: «71 son lo sugerido de carreras sin propuesta»). Una propuesta regresada cuenta lo que pidió.
 */
enum DemandProposalState: string
{
    case Submitted = 'submitted';
    case Accepted = 'accepted';
    case Returned = 'returned';
    case Drafting = 'drafting';
    case Missing = 'missing';

    /**
     * El estado de la propuesta de la carrera en el periodo y la modalidad, o Missing si no la tiene.
     */
    public static function of(?ProposalStatus $status): self
    {
        return match ($status) {
            ProposalStatus::Submitted => self::Submitted,
            ProposalStatus::Accepted => self::Accepted,
            ProposalStatus::Returned => self::Returned,
            ProposalStatus::Draft => self::Drafting,
            null => self::Missing,
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Submitted => 'Enviada',
            self::Accepted => 'Aceptada',
            self::Returned => 'Regresada',
            self::Drafting => 'En captura',
            self::Missing => 'Sin propuesta',
        };
    }

    /**
     * La etiqueta corta del encabezado de su columna en la matriz, donde «Sin propuesta» no cabe.
     */
    public function shortLabel(): string
    {
        return $this === self::Missing ? 'Sin prop.' : $this->label();
    }

    public function icon(): string
    {
        return match ($this) {
            self::Submitted => 'o-paper-airplane',
            self::Accepted => 'o-check-circle',
            self::Returned => 'o-arrow-uturn-left',
            self::Drafting => 'o-pencil',
            self::Missing => 'o-clock',
        };
    }

    /**
     * El tono de su insignia (DESIGN.md, «Insignias de estado»), el mismo de ProposalStatus; sin propuesta, como el borrador.
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Submitted => StatusTone::Info,
            self::Accepted => StatusTone::Success,
            self::Returned => StatusTone::Error,
            self::Drafting, self::Missing => StatusTone::Draft,
        };
    }

    /**
     * Si lo que pidió la carrera cuenta: la propuesta salió de su borrador.
     */
    public function counts(): bool
    {
        return in_array($this, [self::Submitted, self::Accepted, self::Returned], true);
    }
}
