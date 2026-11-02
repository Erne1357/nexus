<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * Por qué una fila de la propuesta de carrera no se corrige: la propuesta ya se aceptó, ya se envió (los dos valen
 * para toda la propuesta) o el departamento de la materia ya aceptó su parte. La página guarda el tipo y la vista
 * arma el texto.
 */
enum ProposalLock: string
{
    case Accepted = 'accepted';
    case Submitted = 'submitted';
    case Department = 'department';

    /**
     * Si el motivo vale para toda la propuesta y no para una fila.
     */
    public function isGeneral(): bool
    {
        return $this !== self::Department;
    }

    /**
     * El texto completo; `$department` es la clave del departamento de la fila.
     */
    public function message(string $department): string
    {
        return match ($this) {
            self::Accepted => 'La propuesta ya se aceptó: ya no cambia.',
            self::Submitted => 'La propuesta ya se envió: solo se corrige si un departamento la regresa.',
            self::Department => "{$department} ya aceptó su parte: sus materias ya no cambian.",
        };
    }

    /**
     * El texto corto de una fila de la tabla.
     */
    public function short(string $department): string
    {
        return $this === self::Department ? "{$department} ya aceptó su parte" : $this->message($department);
    }
}
