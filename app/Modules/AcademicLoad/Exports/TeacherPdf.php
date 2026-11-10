<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;

/**
 * El PDF de la semana de un docente en un campus (tarea 4.5): sus grupos publicados de todos los departamentos, con
 * el salón de cada sesión. Público, como la consulta por docente (el RFC nunca sale).
 */
final class TeacherPdf extends WeekPdf
{
    public function __construct(
        string $termId,
        string $campusId,
        string $termCode,
        string $campusCode,
        string $version,
        public readonly string $teacherId,
        public readonly string $teacherName,
    ) {
        parent::__construct($termId, $campusId, $termCode, $campusCode, $version);
    }

    /**
     * El PDF del docente si tiene grupos publicados en el campus y periodo; null si no.
     */
    public static function find(PublishedScheduleQuery $query, Term $term, Campus $campus, string $teacherId): ?self
    {
        $version = $query->version($term, $campus);
        $teacher = $version === null ? null : collect($query->schedule($term, $campus)->teachers())->firstWhere('id', $teacherId);

        return $teacher === null ? null : new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, $version, $teacher['id'], $teacher['name']);
    }

    public function filename(): string
    {
        return self::clean("Horario {$this->teacherName} - {$this->termCode} {$this->campusCode}").'.pdf';
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
