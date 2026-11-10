<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekGrid;
use Illuminate\Http\Request;

/**
 * El PDF de la carga publicada de un departamento en un campus (tarea 4.5): la tabla tipo SII del semestre con todos
 * los grupos del departamento, más su capacidad y la marca «préstamo» en la sesión que usa un salón de otro
 * departamento. Solo para el personal con sesión (`academic_load.load.export`; decisión del usuario del 10 de noviembre
 * de 2026).
 */
final class DepartmentPdf extends PublishedPdf implements StaffExport
{
    public function __construct(
        string $termId,
        string $campusId,
        string $termCode,
        string $campusCode,
        array $snapshotIds,
        public readonly string $departmentId,
        public readonly string $departmentCode,
        public readonly string $departmentName,
    ) {
        parent::__construct($termId, $campusId, $termCode, $campusCode, $snapshotIds);
    }

    public static function fromRequest(PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?static
    {
        $departmentId = $request->query('departamento');
        $ids = $query->snapshotIds($term, $campus);

        if (! is_string($departmentId) || $ids === []) {
            return null;
        }

        $department = $query->scheduleOf($term, $campus, $ids)->loads[$departmentId]['department'] ?? null;

        return $department === null ? null : new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, $ids, $departmentId, $department['code'], $department['name']);
    }

    public static function label(): string
    {
        return 'PDF del departamento';
    }

    public function permission(): AcademicLoadPermission
    {
        return AcademicLoadPermission::LoadExport;
    }

    public function filename(): string
    {
        return self::clean("Carga {$this->departmentCode} - {$this->termCode} {$this->campusCode}").'.pdf';
    }

    protected function kind(): string
    {
        return 'departamento';
    }

    protected function subject(): array
    {
        return [$this->departmentId];
    }

    protected function document(PublishedSchedule $schedule, Term $term, Campus $campus): array
    {
        $groups = $schedule->department($this->departmentId);
        $ranges = [];

        foreach ($groups as $group) {
            foreach ($group->sessions as $session) {
                $ranges[] = ['day' => $session->day, 'starts_at' => $session->startsAt, 'ends_at' => $session->endsAt];
            }
        }

        return ['academic-load::pdf.semester', [
            'title' => $this->departmentName,
            'subtitle' => count($groups) === 1 ? '1 grupo' : count($groups).' grupos',
            'updated' => self::updated($schedule->updatedAt($groups)),
            'days' => array_map(fn (array $day): array => ['day' => $day['day'], 'name' => $day['name']], WeekGrid::of($schedule->profile())->covering($ranges)->days),
            'subjects' => SemesterPdf::subjects($groups, $schedule),
            'staff' => true,
        ], 'landscape'];
    }
}
