<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * Tipo de acuerdo entre departamentos (docs/dominio/modelo-de-datos.md, `interdepartmental_agreements`): el
 * préstamo de un salón para un día y unas horas (HC-10) o el cupo de otra carrera en un grupo (HC-07).
 */
enum AgreementKind: string
{
    case RoomLoan = 'room_loan';
    case SharedGroup = 'shared_group';

    public function label(): string
    {
        return match ($this) {
            self::RoomLoan => 'Préstamo de salón',
            self::SharedGroup => 'Grupo compartido',
        };
    }

    /** Icono de Heroicons de MaryUI. */
    public function icon(): string
    {
        return match ($this) {
            self::RoomLoan => 'o-building-office',
            self::SharedGroup => 'o-user-group',
        };
    }
}
