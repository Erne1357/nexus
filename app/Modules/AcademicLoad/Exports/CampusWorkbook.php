<?php

namespace App\Modules\AcademicLoad\Exports;

use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Queries\PublishedGroup;
use App\Modules\AcademicLoad\Queries\PublishedScheduleQuery;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Support\WeekTime;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Writer;

/**
 * El Excel de todo lo publicado de un campus y periodo (tarea 4.5): un renglón por sesión de cada grupo (o uno solo
 * si el grupo no tiene horario), con su departamento, materia, grupo, capacidad, docente, carrera dueña, retícula,
 * semestre, día, horas, tipo, salón, edificio y si el salón es prestado. Solo para el personal con sesión
 * (`academic_load.load.export`; decisión del usuario del 10 de noviembre de 2026: el Excel completo, para cualquiera
 * del personal que cubre alguno de los departamentos que publicaron). Se arma con openspout, que ya usa Imports.
 */
final class CampusWorkbook implements StaffExport
{
    public const array HEADER = ['Departamento', 'Clave', 'Materia', 'Grupo', 'Créditos', 'Capacidad', 'Docente', 'Carrera', 'Retícula', 'Semestre', 'Día', 'Inicio', 'Fin', 'Tipo', 'Salón', 'Edificio', 'Préstamo'];

    /**
     * @param  list<string>  $snapshotIds
     */
    public function __construct(
        public readonly string $termId,
        public readonly string $campusId,
        public readonly string $termCode,
        public readonly string $campusCode,
        public readonly array $snapshotIds,
        /** @var list<string> los departamentos que publicaron, para el permiso */
        public readonly array $departmentIds,
    ) {}

    public static function fromRequest(PublishedScheduleQuery $query, Term $term, Campus $campus, Request $request): ?static
    {
        $ids = $query->snapshotIds($term, $campus);

        return $ids === [] ? null : new self($term->getKey(), $campus->getKey(), $term->code, $campus->code, $ids, array_keys($query->departmentsOf($ids)));
    }

    public static function label(): string
    {
        return 'Excel del campus';
    }

    public function key(): string
    {
        return 'excel-'.hash('xxh128', implode('|', [$this->termId, $this->campusId, ...$this->snapshotIds]));
    }

    public function filename(): string
    {
        return "Carga publicada {$this->termCode} {$this->campusCode}.xlsx";
    }

    public function mimeType(): string
    {
        return 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet';
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
        return Target::of(campus: $this->campusId, unit: StaffExports::units($this->departmentIds));
    }

    public function build(): string
    {
        $term = Term::query()->findOrFail($this->termId);
        $campus = Campus::query()->findOrFail($this->campusId);
        $schedule = app(PublishedScheduleQuery::class)->scheduleOf($term, $campus, $this->snapshotIds);
        $path = sys_get_temp_dir().'/nexus-'.Str::uuid7().'.xlsx';

        try {
            $writer = new Writer;
            $writer->openToFile($path);
            $writer->getCurrentSheet()->setName("{$this->termCode} {$this->campusCode}");
            $writer->addRow(Row::fromValuesWithStyle(self::HEADER, (new Style)->withFontBold(true)));

            $profile = $schedule->profile();

            foreach ($schedule->groups() as $group) {
                foreach (self::rows($group, $schedule->loads[$group->departmentId]['department']['code'] ?? '', $profile->usesRooms, $profile->usesTimeBlocks) as $row) {
                    $writer->addRow(Row::fromValues($row));
                }
            }

            $writer->close();

            return (string) file_get_contents($path);
        } finally {
            @unlink($path);
        }
    }

    /**
     * Los renglones de un grupo: uno por sesión, o uno sin horario (también en un campus sin bloques de tiempo). En un
     * campus sin salones, las columnas de salón van vacías en lugar de decir «Por asignar».
     *
     * @return list<list<string|int|null>>
     */
    public static function rows(PublishedGroup $group, string $departmentCode, bool $usesRooms = true, bool $usesTimeBlocks = true): array
    {
        $owner = array_find($group->placements, fn (array $placement): bool => $placement['is_owner']) ?? ($group->placements[0] ?? null);
        $base = [
            $departmentCode,
            $group->subject['code'],
            $group->subject['name'],
            $group->code,
            $group->subject['credits'],
            $group->capacity,
            $group->teacher['name'] ?? 'Por asignar',
            $owner['career']['code'] ?? null,
            $owner['study_plan']['code'] ?? null,
            $owner['semester'] ?? null,
        ];

        if ($group->sessions === [] || ! $usesTimeBlocks) {
            return [[...$base, null, null, null, null, null, null, null]];
        }

        return array_map(fn ($session): array => [
            ...$base,
            ucfirst(WeekTime::day($session->day)),
            WeekTime::clock($session->startsAt),
            WeekTime::clock($session->endsAt),
            $session->kindLabel,
            $usesRooms ? ($session->room['code'] ?? ($session->kind === 'virtual' ? 'Virtual' : 'Por asignar')) : null,
            $usesRooms ? ($session->room['building']['name'] ?? null) : null,
            $usesRooms ? ($session->isLoanedTo($group->departmentId) ? 'Sí' : 'No') : null,
        ], $group->sessions);
    }
}
