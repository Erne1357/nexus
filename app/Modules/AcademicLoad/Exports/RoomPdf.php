<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * El PDF de la semana de un salón en un campus (tarea 4.5): las sesiones publicadas que tiene, de cualquier
 * departamento, con el docente de cada una. Público. Un campus sin salones (`uses_rooms`) no tiene este PDF.
 */
final class RoomPdf extends WeekPdf
{
    public function __construct(
        string $termId,
        string $campusId,
        string $termCode,
        string $campusCode,
        string $version,
        public readonly string $roomId,
        public readonly string $roomCode,
        public readonly string $roomName,
        public readonly string $building,
    ) {
        parent::__construct($termId, $campusId, $termCode, $campusCode, $version);
    }

    /**
     * El PDF del salón si tiene sesiones publicadas en el campus y periodo, y el campus usa salones; null si no.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Campus $campus, string $roomId): ?self
    {
        $version = $query->version($term, $campus);

        if ($version === null) {
            return null;
        }

        $schedule = $query->schedule($term, $campus);
        $room = $schedule->profile()->usesRooms ? collect($schedule->rooms())->firstWhere('id', $roomId) : null;

        return $room === null ? null : new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, $version, $room['id'], $room['code'], $room['name'], $room['building']);
    }

    public function filename(): string
    {
        return self::clean("Horario salón {$this->roomCode} - {$this->termCode} {$this->campusCode}").'.pdf';
    }

    protected function kind(): string
    {
        return 'salon';
    }

    protected function subject(): array
    {
        return [$this->roomId];
    }

    protected function groupsOf(PublishedSchedule $schedule): array
    {
        return $schedule->room($this->roomId);
    }

    protected function title(): string
    {
        return $this->roomCode === $this->roomName ? $this->roomCode : "{$this->roomCode} · {$this->roomName}";
    }

    protected function subtitle(PublishedSchedule $schedule): string
    {
        return $this->building;
    }

    protected function detailOf(PublishedGroup $group, array $session): array
    {
        return [...$session, 'place' => self::teacherOf($group)];
    }
}
