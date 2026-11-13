<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * El PDF de la semana de un salón (tarea 4.5): las sesiones publicadas que tiene en la modalidad (M1), de cualquier
 * departamento, con el docente de cada una. Público. Una modalidad sin salones (`uses_rooms`) no tiene este PDF.
 */
final class RoomPdf extends WeekPdf
{
    public function __construct(
        string $termId,
        string $modalityId,
        string $termCode,
        string $modalityCode,
        array $snapshotIds,
        public readonly string $roomId,
        public readonly string $roomCode,
        public readonly string $roomName,
        public readonly string $building,
        ?string $campusCode = null,
    ) {
        parent::__construct($termId, $modalityId, $termCode, $modalityCode, $snapshotIds, $campusCode);
    }

    /**
     * El PDF del salón si tiene sesiones publicadas en la modalidad y periodo (y en el campus del filtro, si hay), y la
     * modalidad usa salones; null si no.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Modality $modality, ?Campus $campus, string $roomId): ?self
    {
        $ids = $query->snapshotIds($term, $modality);

        if ($ids === []) {
            return null;
        }

        $schedule = $query->scheduleOf($term, $modality, $ids)->onlyCampus($campus?->code);
        $room = $schedule->profile()->usesRooms ? collect($schedule->rooms())->firstWhere('id', $roomId) : null;

        return $room === null ? null : new self($term->getKey(), $modality->getKey(), $term->code, $modality->code, $ids, $room['id'], $room['code'], $room['name'], $room['building'], $campus?->code);
    }

    public function filename(): string
    {
        return self::clean("Horario salón {$this->roomCode} - {$this->termCode} {$this->place()}").'.pdf';
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
