<?php

namespace App\Modules\Facilities\Enums;

/**
 * Tipo de salón. Laboratorio, taller y sala de cómputo son espacios de práctica (HC-06).
 */
enum RoomType: string
{
    case Aula = 'aula';
    case Laboratorio = 'laboratorio';
    case Taller = 'taller';
    case SalaComputo = 'sala_computo';
    case Auditorio = 'auditorio';

    public function label(): string
    {
        return match ($this) {
            self::Aula => 'Aula',
            self::Laboratorio => 'Laboratorio',
            self::Taller => 'Taller',
            self::SalaComputo => 'Sala de cómputo',
            self::Auditorio => 'Auditorio',
        };
    }

    /**
     * Si es un espacio de práctica (HC-06): laboratorio, taller o sala de cómputo.
     */
    public function isPracticeSpace(): bool
    {
        return match ($this) {
            self::Laboratorio, self::Taller, self::SalaComputo => true,
            self::Aula, self::Auditorio => false,
        };
    }
}
