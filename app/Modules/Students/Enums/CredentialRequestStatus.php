<?php

namespace App\Modules\Students\Enums;

/**
 * Estados de una solicitud de credencial (CO-08): solicitada, citada, lista para recoger, entregada, rechazada con
 * motivo o cancelada por el alumno. Abiertas son las tres primeras.
 */
enum CredentialRequestStatus: string
{
    case Solicitada = 'solicitada';
    case Citada = 'citada';
    case Lista = 'lista';
    case Entregada = 'entregada';
    case Rechazada = 'rechazada';
    case Cancelada = 'cancelada';

    public function isOpen(): bool
    {
        return in_array($this, self::open(), true);
    }

    /**
     * @return list<self>
     */
    public static function open(): array
    {
        return [self::Solicitada, self::Citada, self::Lista];
    }

    public function label(): string
    {
        return match ($this) {
            self::Solicitada => 'Solicitada',
            self::Citada => 'Citada',
            self::Lista => 'Lista para recoger',
            self::Entregada => 'Entregada',
            self::Rechazada => 'Rechazada',
            self::Cancelada => 'Cancelada',
        };
    }
}
