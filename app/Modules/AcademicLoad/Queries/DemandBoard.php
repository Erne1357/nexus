<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\DemandProposalState;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Demand\Rules\DemandSettings;
use Carbon\CarbonImmutable;
use Illuminate\Support\Str;

/**
 * El tablero «Demanda por materia» (tarea 5.7; DemandBoardQuery), de todo el Tec en un periodo y una modalidad, en una
 * de dos lentes:
 *
 * - del departamento (`department`): las materias que imparte, con las carreras que las llevan;
 * - de la carrera (`career`): las materias que lleva la carrera, de cualquier departamento, con las demás carreras que
 *   las llevan (la maqueta C con `#rol=coordinador`); la carrera de la lente va primero.
 *
 * `careers` son las columnas: cada carrera con el estado de su propuesta. `subjects` son los renglones (DemandBoardSubject).
 * `campuses`, los campus donde hay algo, por clave. `computedAt` es la proyección más reciente de sus filas. Solo lee:
 * el tablero es de consulta para todos (confirmado el 16 de noviembre de 2026).
 */
final readonly class DemandBoard
{
    /**
     * @param  list<array{career: Career, state: DemandProposalState}>  $careers
     * @param  list<DemandBoardSubject>  $subjects
     * @param  list<Campus>  $campuses
     */
    public function __construct(
        public Term $term,
        public Modality $modality,
        public ?Department $department,
        public ?Career $career,
        public array $careers,
        public array $subjects,
        public array $campuses,
        public ?CarbonImmutable $computedAt,
        public DemandSettings $settings,
    ) {}

    public function isCareerLens(): bool
    {
        return $this->career !== null;
    }

    /**
     * La materia del tablero con esa clave (que puede llegar del cliente), o null si no está.
     */
    public function subject(string $code): ?DemandBoardSubject
    {
        foreach ($this->subjects as $subject) {
            if ($subject->subject->code === $code) {
                return $subject;
            }
        }

        return null;
    }

    /**
     * Cuántas carreras de las columnas ya enviaron su propuesta (cuenta lo que piden).
     */
    public function sentCareers(): int
    {
        return count(array_filter($this->careers, fn (array $column): bool => $column['state']->counts()));
    }

    /**
     * Los departamentos de sus materias, por clave: el filtro «Departamento» de la lente de carrera.
     *
     * @return list<Department>
     */
    public function departments(): array
    {
        $departments = [];

        foreach ($this->subjects as $subject) {
            $department = $subject->subject->department;

            if ($department !== null) {
                $departments[$department->getKey()] = $department;
            }
        }

        uasort($departments, fn (Department $a, Department $b): int => strcmp($a->code, $b->code));

        return array_values($departments);
    }

    /**
     * El mismo tablero con solo las materias de un departamento y las carreras que las llevan (la lente de carrera
     * filtrada por «Departamento»).
     */
    public function withinDepartment(?Department $department): self
    {
        if ($department === null) {
            return $this;
        }

        $subjects = array_values(array_filter($this->subjects, fn (DemandBoardSubject $subject): bool => $subject->subject->department_id === $department->getKey()));
        $careerIds = array_unique(array_merge([], ...array_map(fn (DemandBoardSubject $subject): array => $subject->careerIds(), $subjects)));

        return new self(
            $this->term, $this->modality, $this->department, $this->career,
            array_values(array_filter($this->careers, fn (array $column): bool => in_array($column['career']->getKey(), $careerIds, true))),
            $subjects, $this->campuses, $this->computedAt, $this->settings,
        );
    }

    /**
     * Las cuatro cifras de todo el Tec: materias (con diferencia y sin propuesta), grupos sugeridos y grupos que cuentan
     * como pedidos (por clave de campus y los de materias con laboratorio; `pendingSuggested`, los que son lo sugerido de
     * carreras sin propuesta) y la diferencia, en grupos, en porcentaje de lo sugerido y en horas a la semana de
     * docentes (Σ de la diferencia de cada materia por sus horas a la semana).
     *
     * @return array{subjects: int, withDifference: int, withoutProposal: int, suggested: int, suggestedByCampus: array<string, int>, suggestedLab: int, counted: int, countedByCampus: array<string, int>, countedLab: int, pendingSuggested: int, difference: int, percent: ?int, large: bool, hours: int}
     */
    public function figures(): array
    {
        $suggestedByCampus = $countedByCampus = array_fill_keys(array_map(fn (Campus $campus): string => $campus->code, $this->campuses), 0);
        $suggested = $counted = $suggestedLab = $countedLab = $pending = $hours = 0;

        foreach ($this->subjects as $subject) {
            $total = $subject->total();
            $suggested += $total->suggested;
            $counted += $total->counted;
            $hours += $total->difference() * $subject->weeklyHours();

            if ($subject->subject->needs_lab) {
                $suggestedLab += $total->suggested;
                $countedLab += $total->counted;
            }

            foreach ($subject->rows as $row) {
                if ($row->campus !== null) {
                    $suggestedByCampus[$row->campus->code] += $row->suggested;
                    $countedByCampus[$row->campus->code] += $row->counted();
                }

                $pending += $row->state->counts() ? 0 : $row->suggested;
            }
        }

        $total = new DemandBoardCell($suggested, $counted, large: $this->settings->isLargeDifference($suggested, $counted));

        return [
            'subjects' => count($this->subjects),
            'withDifference' => count(array_filter($this->subjects, fn (DemandBoardSubject $subject): bool => $subject->hasDifference())),
            'withoutProposal' => count(array_filter($this->subjects, fn (DemandBoardSubject $subject): bool => $subject->hasPending())),
            'suggested' => $suggested,
            'suggestedByCampus' => $suggestedByCampus,
            'suggestedLab' => $suggestedLab,
            'counted' => $counted,
            'countedByCampus' => $countedByCampus,
            'countedLab' => $countedLab,
            'pendingSuggested' => $pending,
            'difference' => $total->difference(),
            'percent' => $total->percent(),
            'large' => $total->large,
            'hours' => $hours,
        ];
    }

    /**
     * Los grupos que cuentan como pedidos en cada campus, por clave de campus: las cifras del filtro de campus.
     *
     * @return array<string, int>
     */
    public function countedByCampus(): array
    {
        return $this->figures()['countedByCampus'];
    }

    /**
     * Cuántas materias hay en cada opción del filtro «Todas · Con diferencia · Sin propuesta», en el campus del filtro.
     *
     * @return array{all: int, difference: int, pending: int}
     */
    public function filterCounts(?string $campusId = null): array
    {
        $subjects = $this->inCampus($campusId);

        return [
            'all' => count($subjects),
            'difference' => count(array_filter($subjects, fn (DemandBoardSubject $subject): bool => $subject->hasDifference($campusId))),
            'pending' => count(array_filter($subjects, fn (DemandBoardSubject $subject): bool => $subject->hasPending($campusId))),
        ];
    }

    /**
     * Los renglones que se ven: los del campus del filtro, con la búsqueda por clave o nombre (sin distinguir mayúsculas
     * ni acentos) y el filtro `difference` o `pending` ('' son todas).
     *
     * @return list<DemandBoardSubject>
     */
    public function visible(string $search = '', string $filter = '', ?string $campusId = null): array
    {
        $needle = self::normalize($search);

        return array_values(array_filter($this->inCampus($campusId), fn (DemandBoardSubject $subject): bool => ($needle === '' || str_contains(self::normalize("{$subject->subject->code} {$subject->subject->name}"), $needle))
            && match ($filter) {
                'difference' => $subject->hasDifference($campusId),
                'pending' => $subject->hasPending($campusId),
                default => true,
            }));
    }

    /**
     * El pie de la matriz: por carrera, la suma de sus celdas en esos renglones, por clave de carrera; y el total.
     *
     * @param  list<DemandBoardSubject>  $subjects
     * @return array{careers: array<string, DemandBoardCell>, total: DemandBoardCell}
     */
    public function footer(array $subjects, ?string $campusId = null): array
    {
        $rows = array_merge([], ...array_map(fn (DemandBoardSubject $subject): array => $subject->rowsIn($campusId), $subjects));
        $careers = [];

        foreach ($this->careers as $column) {
            $id = $column['career']->getKey();
            $mine = array_values(array_filter($rows, fn (DemandBoardRow $row): bool => $row->career->getKey() === $id));
            $careers[$id] = new DemandBoardCell(
                array_sum(array_map(fn (DemandBoardRow $row): int => $row->suggested, $mine)),
                array_sum(array_map(fn (DemandBoardRow $row): int => $row->counted(), $mine)),
                ! $column['state']->counts(),
            );
        }

        return ['careers' => $careers, 'total' => DemandBoardSubject::sum($rows, $this->settings)];
    }

    /**
     * @return list<DemandBoardSubject>
     */
    private function inCampus(?string $campusId): array
    {
        return $campusId === null ? $this->subjects : array_values(array_filter($this->subjects, fn (DemandBoardSubject $subject): bool => $subject->rowsIn($campusId) !== []));
    }

    private static function normalize(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }
}
