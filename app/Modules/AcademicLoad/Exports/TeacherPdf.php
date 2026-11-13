<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;

/**
 * El PDF de la semana de un docente en una modalidad (tarea 4.5; M1, o en el campus del filtro): sus grupos publicados de todos los departamentos, con
 * el salón de cada sesión. Público, como la consulta por docente (el RFC nunca sale).
 */
final class TeacherPdf extends WeekPdf
{
    public function __construct(
        string $termId,
        string $modalityId,
        string $termCode,
        string $modalityCode,
        array $snapshotIds,
        public readonly string $teacherId,
        public readonly string $teacherName,
        ?string $campusCode = null,
    ) {
        parent::__construct($termId, $modalityId, $termCode, $modalityCode, $snapshotIds, $campusCode);
    }

    /**
     * El PDF del docente si tiene grupos publicados en la modalidad y periodo (y en el campus del filtro, si hay); null si
     * no.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Modality $modality, ?Campus $campus, string $teacherId): ?self
    {
        $ids = $query->snapshotIds($term, $modality);
        $teacher = $ids === [] ? null : collect($query->scheduleOf($term, $modality, $ids)->onlyCampus($campus?->code)->teachers())->firstWhere('id', $teacherId);

        return $teacher === null ? null : new self($term->getKey(), $modality->getKey(), $term->code, $modality->code, $ids, $teacher['id'], $teacher['name'], $campus?->code);
    }

    public function filename(): string
    {
        return self::clean("Horario {$this->teacherName} - {$this->termCode} {$this->place()}").'.pdf';
    }

    protected function kind(): string
    {
        return 'docente';
    }

    protected function subject(): array
    {
        return [$this->teacherId];
    }

    protected function groupsOf(PublishedSchedule $schedule): array
    {
        return $schedule->teacher($this->teacherId);
    }

    protected function title(): string
    {
        return $this->teacherName;
    }

    protected function subtitle(PublishedSchedule $schedule): string
    {
        $groups = count($this->groupsOf($schedule));

        return $groups === 1 ? '1 grupo' : "{$groups} grupos";
    }

    protected function detailOf(PublishedGroup $group, array $session): array
    {
        return $session;
    }
}
