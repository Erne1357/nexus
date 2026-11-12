<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Queries\PublishedSchedule;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use Illuminate\Http\Request;

/**
 * El PDF de la carga publicada de un departamento en un campus (tarea 4.5): la tabla tipo SII del semestre con todos
 * los grupos del departamento, más su capacidad y la marca «préstamo» en la sesión que usa un salón de otro
 * departamento. Solo para el personal con sesión (`academic_load.load.export`; decisión del usuario del 10 de noviembre
 * de 2026): como el Excel del campus, lo descarga quien cubre alguno de los departamentos que publicaron, porque lo
 * publicado no es secreto entre el personal. Se arma solo con la versión del departamento, así que otro departamento
 * que republica no lo invalida.
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
        /** @var list<string> los departamentos que publicaron en el campus, para el permiso */
        public readonly array $departmentIds,
    ) {
        parent::__construct($termId, $campusId, $termCode, $campusCode, $snapshotIds);
    }

    public static function fromRequest(PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?static
    {
        $departmentId = $request->query('departamento');
        if (! is_string($departmentId)) {
            return null;
        }

        $departments = $query->departmentsOf($query->snapshotIds($term, $campus));
        $department = $departments[$departmentId] ?? null;

        return $department === null ? null : new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, [$department['snapshot_id']], $department['id'], $department['code'], $department['name'], array_keys($departments));
    }

    public static function label(): string
    {
        return 'PDF del departamento';
    }

    public static function requiredPermission(): AcademicLoadPermission
    {
        return AcademicLoadPermission::LoadExport;
    }

    public function permission(): AcademicLoadPermission
    {
        return self::requiredPermission();
    }

    public function scope(): Target
    {
        return Target::of(modality: $this->modalityId($this->campusId), unit: StaffExports::units($this->departmentIds));
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

        return ['academic-load::pdf.semester', [
            'title' => $this->departmentName,
            'subtitle' => count($groups) === 1 ? '1 grupo' : count($groups).' grupos',
            'updated' => self::updated($schedule->updatedAt($groups)),
            'days' => SemesterPdf::days($groups, $schedule),
            'subjects' => SemesterPdf::subjects($groups, $schedule),
            'staff' => true,
        ], 'landscape'];
    }
}
