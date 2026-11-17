<?php

namespace App\Modules\AcademicLoad\Enums;

/**
 * En qué va una evaluación de la proyección: en la cola, corriendo, lista o fallida.
 */
enum ForecastEvaluationStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Running => 'Calculando',
            self::Done => 'Lista',
            self::Failed => 'Falló',
        };
    }
}
