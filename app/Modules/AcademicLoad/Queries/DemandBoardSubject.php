<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Subject;
use App\Modules\Core\Models\Campus;
use App\Modules\Demand\Rules\DemandSettings;

/**
 * Un renglón del tablero «Demanda por materia» (tarea 5.7): la materia (con su departamento), su semestre en la carrera
 * de la lente de carrera (null en la del departamento) y sus filas, una por carrera y campus. Las celdas se arman aquí,
 * sumando las filas del campus del filtro (null es «Todos»):
 *
 * - la de una carrera: lo sugerido y lo que cuenta como pedido; pendiente si la carrera no ha enviado, y resaltada si
 *   se aparta de lo sugerido (DemandSettings::isLargeDifference), nunca pendiente y resaltada a la vez;
 * - la de «Todo el Tec»: las de todas las carreras, resaltada con la misma regla.
 *
 * La materia tiene diferencia si alguna celda de carrera o su total están resaltados, y está sin propuesta si alguna
 * carrera suya no ha enviado (supuesto por confirmar: «Con diferencia» y «Sin propuesta» son lo que la matriz muestra
 * con ese campus).
 */
final readonly class DemandBoardSubject
{
    /**
     * @param  list<DemandBoardRow>  $rows  por clave de carrera y de campus (en la lente de carrera, la suya primero)
     */
    public function __construct(
        public Subject $subject,
        public ?int $semester,
        public array $rows,
        public DemandSettings $settings,
    ) {}

    /**
     * Las horas a la semana de un grupo de la materia: teoría más práctica.
     */
    public function weeklyHours(): int
    {
        return (int) $this->subject->theory_hours + (int) $this->subject->practice_hours;
    }

    /**
     * Sus filas en el campus del filtro (todas sin él).
     *
     * @return list<DemandBoardRow>
     */
    public function rowsIn(?string $campusId): array
    {
        return $campusId === null ? $this->rows : array_values(array_filter($this->rows, fn (DemandBoardRow $row): bool => $row->campusId() === $campusId));
    }

    /**
     * La celda de una carrera en el campus del filtro, o null si la carrera no lleva la materia ahí.
     */
    public function cell(string $careerId, ?string $campusId = null): ?DemandBoardCell
    {
        $rows = array_values(array_filter($this->rowsIn($campusId), fn (DemandBoardRow $row): bool => $row->career->getKey() === $careerId));

        if ($rows === []) {
            return null;
        }

        $suggested = array_sum(array_map(fn (DemandBoardRow $row): int => $row->suggested, $rows));
        $counted = array_sum(array_map(fn (DemandBoardRow $row): int => $row->counted(), $rows));
        $pending = ! $rows[0]->state->counts();

        return new DemandBoardCell($suggested, $counted, $pending, ! $pending && $this->settings->isLargeDifference($suggested, $counted));
    }

    /**
     * La celda de «Todo el Tec»: todas las carreras en el campus del filtro.
     */
    public function total(?string $campusId = null): DemandBoardCell
    {
        return self::sum($this->rowsIn($campusId), $this->settings);
    }

    /**
     * El total de cada campus donde se da, por clave de campus; vacío en una modalidad sin salones.
     *
     * @return list<array{campus: Campus, cell: DemandBoardCell}>
     */
    public function byCampus(): array
    {
        $campuses = [];

        foreach ($this->rows as $row) {
            if ($row->campus !== null) {
                $campuses[$row->campus->getKey()] = $row->campus;
            }
        }

        uasort($campuses, fn (Campus $a, Campus $b): int => strcmp($a->code, $b->code));

        return array_values(array_map(fn (Campus $campus): array => ['campus' => $campus, 'cell' => $this->total($campus->getKey())], $campuses));
    }

    /**
     * El desglose del drawer: por campus (null en una modalidad sin salones) y de todo el Tec, los elegibles, los esperados
     * y la celda de sugeridos contra pedidos.
     *
     * @return array{campuses: list<array{campus: ?Campus, eligible: int, expected: int, cell: DemandBoardCell}>, total: array{eligible: int, expected: int, cell: DemandBoardCell}}
     */
    public function breakdown(): array
    {
        $line = fn (array $rows): array => [
            'eligible' => array_sum(array_map(fn (DemandBoardRow $row): int => $row->eligible, $rows)),
            'expected' => array_sum(array_map(fn (DemandBoardRow $row): int => $row->expected, $rows)),
            'cell' => self::sum($rows, $this->settings),
        ];
        $byCampus = [];

        foreach ($this->rows as $row) {
            $byCampus[$row->campusId() ?? ''][] = $row;
        }

        $campuses = array_map(fn (array $rows): array => ['campus' => $rows[0]->campus, ...$line($rows)], array_values($byCampus));
        usort($campuses, fn (array $a, array $b): int => [$a['campus'] === null, $a['campus']->code ?? ''] <=> [$b['campus'] === null, $b['campus']->code ?? '']);

        return ['campuses' => $campuses, 'total' => $line($this->rows)];
    }

    /**
     * Las claves de las carreras que la llevan en el campus del filtro.
     *
     * @return list<string>
     */
    public function careerIds(?string $campusId = null): array
    {
        return array_values(array_unique(array_map(fn (DemandBoardRow $row): string => $row->career->getKey(), $this->rowsIn($campusId))));
    }

    /**
     * Si alguna celda de carrera o su total, en el campus del filtro, se resaltan. Una pasada por sus filas.
     */
    public function hasDifference(?string $campusId = null): bool
    {
        $rows = $this->rowsIn($campusId);

        if ($rows === []) {
            return false;
        }

        if (self::sum($rows, $this->settings)->large) {
            return true;
        }

        $byCareer = [];

        foreach ($rows as $row) {
            $byCareer[$row->career->getKey()][] = $row;
        }

        foreach ($byCareer as $careerRows) {
            if ($careerRows[0]->state->counts() && self::sum($careerRows, $this->settings)->large) {
                return true;
            }
        }

        return false;
    }

    public function hasPending(?string $campusId = null): bool
    {
        foreach ($this->rowsIn($campusId) as $row) {
            if (! $row->state->counts()) {
                return true;
            }
        }

        return false;
    }

    /**
     * La celda que suma esas filas: sugeridos, lo que cuenta como pedido y si se resalta.
     *
     * @param  list<DemandBoardRow>  $rows
     */
    public static function sum(array $rows, DemandSettings $settings): DemandBoardCell
    {
        $suggested = array_sum(array_map(fn (DemandBoardRow $row): int => $row->suggested, $rows));
        $counted = array_sum(array_map(fn (DemandBoardRow $row): int => $row->counted(), $rows));

        return new DemandBoardCell($suggested, $counted, large: $settings->isLargeDifference($suggested, $counted));
    }
}
