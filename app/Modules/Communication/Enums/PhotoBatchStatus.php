<?php

namespace App\Modules\Communication\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * En qué va un lote de fotos: en cola, procesando, terminado o fallido (el job no pudo con el ZIP).
 */
enum PhotoBatchStatus: string
{
    case EnCola = 'en_cola';
    case Procesando = 'procesando';
    case Terminado = 'terminado';
    case Fallido = 'fallido';

    public function label(): string
    {
        return match ($this) {
            self::EnCola => 'En cola',
            self::Procesando => 'Procesando',
            self::Terminado => 'Terminado',
            self::Fallido => 'Falló',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::EnCola, self::Procesando => StatusTone::Info,
            self::Terminado => StatusTone::Success,
            self::Fallido => StatusTone::Error,
        };
    }

    /**
     * Si todavía no termina: la pantalla lo sondea cada 2 segundos.
     */
    public function isPending(): bool
    {
        return $this === self::EnCola || $this === self::Procesando;
    }
}
