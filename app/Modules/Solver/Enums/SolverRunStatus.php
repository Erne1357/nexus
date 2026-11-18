<?php

namespace App\Modules\Solver\Enums;

use App\Modules\Core\Enums\StatusTone;

/**
 * El ciclo de vida de una corrida (docs/arquitectura/solver.md): en cola, corriendo, terminada (con salida válida del
 * contrato), aplicando (el jefe la aceptó y la cola escribe el horario), fallida, aplicada (el jefe la aceptó y se escribió en `group_sessions`), descartada (el jefe la descartó
 * sin aplicarla) y cancelada (se canceló antes de terminar, SV-03). En cola, corriendo y aplicando cuentan como «en curso»
 * para la corrida única de un departamento (SV-01); las demás son terminales salvo terminada, que se aplica o se
 * descarta (tarea 6.10).
 */
enum SolverRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Applying = 'applying';
    case Applied = 'applied';
    case Discarded = 'discarded';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Running => 'Corriendo',
            self::Done => 'Lista',
            self::Failed => 'Fallida',
            self::Applying => 'Aplicando',
            self::Applied => 'Aplicada',
            self::Discarded => 'Descartada',
            self::Cancelled => 'Cancelada',
        };
    }

    /**
     * El tono de su insignia (DESIGN.md, «Insignias de estado»).
     */
    public function tone(): StatusTone
    {
        return match ($this) {
            self::Queued, self::Running, self::Applying => StatusTone::Info,
            self::Done => StatusTone::Success,
            self::Failed => StatusTone::Error,
            self::Applied => StatusTone::SuccessSolid,
            self::Discarded, self::Cancelled => StatusTone::Neutral,
        };
    }

    public function icon(): string
    {
        return match ($this) {
            self::Queued => 'o-clock',
            self::Running, self::Applying => 'o-arrow-path',
            self::Done, self::Applied => 'o-check-circle',
            self::Failed => 'o-x-circle',
            self::Discarded => 'o-archive-box-x-mark',
            self::Cancelled => 'o-stop-circle',
        };
    }

    /**
     * Aplicando: el jefe la aceptó y el job escribe el horario (SV-05).
     */
    public function isApplying(): bool
    {
        return $this === self::Applying;
    }

    /**
     * Las que ocupan el lugar de la corrida única de un departamento (SV-01): en cola, corriendo o aplicando.
     *
     * @return list<self>
     */
    public static function occupying(): array
    {
        return [self::Queued, self::Running, self::Applying];
    }

    /**
     * En cola o corriendo: la que ocupa el lugar de la corrida única de un departamento (SV-01) y la que se cancela.
     */
    public function isInProgress(): bool
    {
        return $this === self::Queued || $this === self::Running;
    }
}
