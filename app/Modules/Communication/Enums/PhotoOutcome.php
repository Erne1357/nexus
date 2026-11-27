<?php

namespace App\Modules\Communication\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * Qué pasó con un archivo del ZIP (el renglón del reporte de `photo_batches.report`): aplicada; sin persona con ese
 * nombre; es alumno (sus fotos van aparte); formato inválido (no es jpg, png o webp, o no se pudo leer); muy pesada (más
 * de 10 MB); repetida (otro archivo del lote ya traía esa clave); no guardada (el disco no dejó escribirla: se avisa al administrador).
 */
enum PhotoOutcome: string
{
    case Aplicada = 'aplicada';
    case SinPersona = 'sin_persona';
    case EsAlumno = 'es_alumno';
    case FormatoInvalido = 'formato_invalido';
    case MuyPesada = 'muy_pesada';
    case Repetida = 'repetida';
    case NoGuardada = 'no_guardada';

    public function label(): string
    {
        return match ($this) {
            self::Aplicada => 'Aplicada',
            self::SinPersona => 'Sin persona',
            self::EsAlumno => 'Es alumno',
            self::FormatoInvalido => 'Formato inválido',
            self::MuyPesada => 'Muy pesada',
            self::Repetida => 'Repetida',
            self::NoGuardada => 'No se pudo guardar',
        };
    }

    public function tone(): StatusTone
    {
        return match ($this) {
            self::Aplicada => StatusTone::Success,
            self::SinPersona, self::EsAlumno, self::Repetida => StatusTone::Warning,
            self::FormatoInvalido, self::MuyPesada, self::NoGuardada => StatusTone::Error,
        };
    }
}
