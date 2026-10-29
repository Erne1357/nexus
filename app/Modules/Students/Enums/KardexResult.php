<?php

namespace App\Modules\Students\Enums;

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

    public function approves(): bool
    {
        return $this === self::Aprobada;
    }
}
