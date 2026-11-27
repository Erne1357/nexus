<?php

namespace App\Modules\Students\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Resultado de una materia en el kardex (`kardex_records.result`). Solo `aprobada` aprueba: quien está
 * cursando la materia todavía no la tiene aprobada (reglas-de-negocio.md, "Elegibilidad y demanda").
 */
enum KardexResult: string
{
    case Aprobada = 'aprobada';
    case Reprobada = 'reprobada';
    case Cursando = 'cursando';
    case Baja = 'baja';

    public function label(): string
    {
        return match ($this) {
            self::Aprobada => 'Aprobada',
            self::Reprobada => 'Reprobada',
            self::Cursando => 'Cursando',
            self::Baja => 'Baja',
        };
    }

    /**
     * Tono de su insignia en el kárdex de la ficha (DESIGN.md, "Insignias de estado").
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Aprobada => StatusTone::Success,
            self::Reprobada => StatusTone::Error,
            self::Cursando => StatusTone::Info,
            self::Baja => StatusTone::Neutral,
        };
    }

    public function approves(): bool
    {
        return $this === self::Aprobada;
    }
}
