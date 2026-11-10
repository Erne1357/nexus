<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Core\Models\CampusProfile;

/**
 * Las banderas de HC-15 de un grupo activo (GroupClosingFlagsQuery) y la regla por grupo que leen el veto
 * (ClosingBlockersQuery), «Cierre de la carga» (los grupos que se justifican) y «Avance del cierre»: la única copia de lo
 * que le falta a un grupo para cerrar, según el perfil de su campus (confirmado el 10 de noviembre de 2026).
 *
 * - **choque duro**: lo decide quien cuenta (ClosingBlockers::tally): el validador al momento en el veto y en la pantalla,
 *   o `storedHardConflict`, el vigente de `schedule_conflicts`, en el tablero;
 * - **sin horario**: sin sesiones activas, si el campus usa bloques de tiempo;
 * - **sin docente** sin justificación;
 * - **sin salón**: alguna sesión activa presencial (no virtual) sin salón, si el campus usa salones, sin justificación;
 * - **docente dado de baja**: inactivo o borrado; no se justifica (P10).
 */
final readonly class GroupClosingFlags
{
    /**
     * @param  int  $roomlessSessions  sus sesiones activas presenciales sin salón
     * @param  bool  $justified  si tiene justificación de cierre (un texto en blanco no cuenta)
     * @param  bool  $storedHardConflict  si tiene un choque duro vigente en `schedule_conflicts`
     */
    public function __construct(
        public string $id,
        public string $campusId,
        public string $departmentId,
        public ?string $teacherId,
        public bool $hasSessions,
        public int $roomlessSessions,
        public bool $inactiveTeacher,
        public bool $justified,
        public bool $storedHardConflict,
    ) {}

    public function lacksTeacher(): bool
    {
        return $this->teacherId === null;
    }

    public function unscheduled(CampusProfile $profile): bool
    {
        return $profile->usesTimeBlocks && ! $this->hasSessions;
    }

    public function withoutTeacher(): bool
    {
        return $this->lacksTeacher() && ! $this->justified;
    }

    public function withoutRoom(CampusProfile $profile): bool
    {
        return $this->lacksRoom($profile) && ! $this->justified;
    }

    /**
     * Si es de los que se justifican: sin docente o, si el campus usa salones, con sesiones presenciales sin salón.
     */
    public function needsJustification(CampusProfile $profile): bool
    {
        return $this->lacksTeacher() || $this->lacksRoom($profile);
    }

    /**
     * Si pasa el cierre por su justificación.
     */
    public function passesJustified(CampusProfile $profile): bool
    {
        return $this->justified && $this->needsJustification($profile);
    }

    /**
     * Si detiene el cierre: con choque duro (`$conflicted`), sin horario, sin docente, sin salón o con docente de baja.
     */
    public function blocks(CampusProfile $profile, bool $conflicted): bool
    {
        return $conflicted || $this->unscheduled($profile) || $this->withoutTeacher() || $this->withoutRoom($profile) || $this->inactiveTeacher;
    }

    private function lacksRoom(CampusProfile $profile): bool
    {
        return $profile->usesRooms && $this->roomlessSessions > 0;
    }
}
