<?php

namespace App\Modules\AcademicLoad\Enums;

use App\Modules\Core\Enums\StatusTone;

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

    /**
     * El tono de su insignia en «Precisión de la proyección» (DESIGN.md, «Insignias de estado»).
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Queued, self::Running => StatusTone::Info,
            self::Done => StatusTone::Success,
            self::Failed => StatusTone::Error,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'o-clock',
            self::Running => 'o-arrow-path',
            self::Done => 'o-check-circle',
            self::Failed => 'o-x-circle',
        };
    }

    /**
     * Si todavía no termina: en cola o corriendo.
     */
    public function isPending(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
