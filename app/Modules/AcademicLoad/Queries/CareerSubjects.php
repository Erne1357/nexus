<?php

namespace App\Modules\AcademicLoad\Queries;

use App\Modules\Academic\Models\Career;
use App\Modules\AcademicLoad\Rules\ForecastAccuracy;
use App\Modules\Core\Models\Campus;
use Illuminate\Support\Collection;

/**
 * Las materias de una carrera en una evaluación (el drawer de «Precisión de la proyección»; ForecastEvaluationsQuery::
 * subjects), con el filtro de campus del drawer y el de departamento de la página. `subjects` va de la de mayor error a
 * la de menor: primero las que nadie cursó y se proyectaron, luego por error relativo y al final las que no cuentan
 * (proyectado y real en cero, DM-21). `campuses` son los campus de la carrera en la evaluación.
 *
 * @phpstan-type SubjectAccuracy array{code: string, name: string, department: ?string, campus: ?Campus, eligible: int, projected: int, actual: int, suggestedGroups: int, actualGroups: ?int, method: string, methodLabel: ?string, error: ?float, out: bool, noHistory: bool, counts: bool}
 */
final readonly class CareerSubjects
{
    /**
     * @param  list<SubjectAccuracy>  $subjects
     * @param  Collection<int, Campus>  $campuses
     */
    public function __construct(
        public Career $career,
        public ForecastMetrics $metrics,
        public array $subjects,
        public Collection $campuses,
        public ?Campus $campus,
    ) {}

    /**
     * Cuántas materias muestra cada filtro del drawer: todas, fuera del 15 % y sin historia propia.
     *
     * @return array{all: int, out: int, noHistory: int}
     */
    public function counts(): array
    {
        return [
            'all' => count($this->subjects),
            'out' => count(array_filter($this->subjects, fn (array $subject): bool => $subject['out'])),
            'noHistory' => count(array_filter($this->subjects, fn (array $subject): bool => $subject['noHistory'])),
        ];
    }

    /**
     * Las materias con alumnos y cuántas de ellas quedaron dentro del 15 % (DM-23).
     *
     * @return array{withStudents: int, within: int, projected: int, actual: int}
     */
    public function totals(): array
    {
        $withStudents = count(array_filter($this->subjects, fn (array $subject): bool => $subject['actual'] > 0));

        return [
            'withStudents' => $withStudents,
            'within' => (int) round(($this->metrics->withinShare ?? 0) * $withStudents),
            'projected' => array_sum(array_column($this->subjects, 'projected')),
            'actual' => array_sum(array_column($this->subjects, 'actual')),
        ];
    }

    /**
     * La gráfica de dispersión, en porcentajes de su caja: cada materia que cuenta es un punto (reales a la derecha,
     * proyectados arriba) y la banda del 15 % rodea la diagonal. La escala sube de 25 en 25, de 50 en 50 o de 100 en 100
     * según la materia más grande.
     *
     * @return array{top: int, ticks: list<int>, band: string, points: list<array{x: float, y: float, out: bool, label: string}>}
     */
    public function scatter(): array
    {
        $counted = array_values(array_filter($this->subjects, fn (array $subject): bool => $subject['counts']));
        $max = max(20, ...array_map(fn (array $subject): int => max($subject['projected'], $subject['actual']), $counted ?: [['projected' => 0, 'actual' => 0]]));
        $step = $max > 300 ? 100 : ($max > 150 ? 50 : 25);
        $top = (int) (ceil($max / $step) * $step);
        $meta = ForecastAccuracy::STRONG_THRESHOLD;
        $percent = fn (float $value): string => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');

        return [
            'top' => $top,
            'ticks' => range(0, $top, $step),
            'band' => '0,100 '.$percent(100 / (1 + $meta)).',0 100,0 100,'.$percent($meta * 100),
            'points' => array_map(fn (array $subject): array => [
                'x' => round($subject['actual'] / $top * 100, 2),
                'y' => round($subject['projected'] / $top * 100, 2),
                'out' => $subject['out'],
                'label' => "{$subject['code']} {$subject['name']}".($subject['campus'] === null ? '' : " · {$subject['campus']->code}").": {$subject['projected']} proyectados, {$subject['actual']} reales",
            ], $counted),
        ];
    }
}
