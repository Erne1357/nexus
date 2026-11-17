<?php

namespace App\Modules\Solver\Enums;

/**
 * El ciclo de vida de una corrida (docs/arquitectura/solver.md): en cola, corriendo, terminada (con salida válida del
 * contrato), fallida y aplicada (el jefe la aceptó y se escribió en `group_sessions`; es terminal). Descartar una
 * corrida terminada no le cambia el estado.
 */
enum SolverRunStatus: string
{
    case Queued = 'queued';
    case Running = 'running';
    case Done = 'done';
    case Failed = 'failed';
    case Applied = 'applied';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'En cola',
            self::Running => 'Corriendo',
            self::Done => 'Terminada',
            self::Failed => 'Fallida',
            self::Applied => 'Aplicada',
        };
    }
}
