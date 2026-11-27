<?php

namespace App\Modules\Students\Enums;

/**
 * Motivo de la reposición de la credencial física (CO-12, confirmado el 26 de noviembre de 2026): por primera vez, o por
 * daño o extravío, según tuviera ya la tarjeta Santander o no.
 */
enum RepositionReason: string
{
    case FirstTime = 'first_time';
    case LostNotSantander = 'lost_not_santander';
    case LostWasSantander = 'lost_was_santander';

    public function label(): string
    {
        return match ($this) {
            self::FirstTime => 'Por primera vez',
            self::LostNotSantander => 'Por daño o extravío (no era tarjeta Santander)',
            self::LostWasSantander => 'Por daño o extravío (ya contaba con tarjeta Santander)',
        };
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(fn (self $reason): string => $reason->value, self::cases());
    }
}
