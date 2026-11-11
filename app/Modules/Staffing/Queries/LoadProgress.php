<?php

namespace App\Modules\Staffing\Queries;

use App\Modules\AcademicLoad\Enums\LoadStatus;
use App\Modules\AcademicLoad\Models\AcademicLoad;

/**
 * Cuánto le falta a una carga para cerrar en el tablero «Avance del cierre» (ClosingBoardQuery): las mismas cifras que
 * «Para cerrar», contadas por grupo, pero con los choques duros vigentes de `schedule_conflicts` y no con el validador,
 * que tardaría con todas las cargas del Tec. El veto sigue decidiendo al cerrar. `blockingGroups` son los grupos
 * distintos que faltan (la cifra de «C2 · N»): uno sin docente ni salón cuenta en dos columnas y ahí una vez.
 */
final readonly class LoadProgress
{
    public function __construct(
        public AcademicLoad $load,
        public int $groups = 0,
        public int $hard = 0,
        public int $unscheduled = 0,
        public int $withoutTeacher = 0,
        public int $withoutRoom = 0,
        public int $inactiveTeacher = 0,
        public int $requests = 0,
        public int $shared = 0,
        public int $awaiting = 0,
        public int $justified = 0,
        public int $blockingGroups = 0,
    ) {}

    /**
     * Docente dado de baja, solicitudes de cambio y acuerdos: la columna «Otros».
     */
    public function others(): int
    {
        return $this->inactiveTeacher + $this->requests + $this->shared + $this->awaiting;
    }

    /**
     * Los trámites que faltan, aparte de los grupos: solicitudes de cambio y acuerdos.
     */
    public function procedures(): int
    {
        return $this->requests + $this->shared + $this->awaiting;
    }

    /**
     * Si falta algo: grupos o trámites.
     */
    public function missing(): int
    {
        return $this->blockingGroups + $this->procedures();
    }

    /**
     * En qué cubeta del tablero cae:
     * - `published`: publicada;
     * - `closed`: cerrada o aprobada, falta publicar;
     * - `ready`: en asignación y sin nada que falte, lista para cerrar;
     * - `missing`: lo demás que sigue en proceso (con faltantes, o en propuesta o ajustes, que antes de cerrar pasan por
     *   asignación).
     */
    public function bucket(): string
    {
        return match (true) {
            in_array($this->load->status, [LoadStatus::Published, LoadStatus::Reanalysis], true) => 'published',
            in_array($this->load->status, [LoadStatus::Closed, LoadStatus::Approved], true) => 'closed',
            $this->load->status === LoadStatus::Staffing && $this->missing() === 0 => 'ready',
            default => 'missing',
        };
    }
}
