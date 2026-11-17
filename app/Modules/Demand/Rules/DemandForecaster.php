<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\ForecastMethod;
use App\Modules\Demand\Enums\GapBucket;

/**
 * La fórmula de la proyección de demanda (reglas-de-negocio.md, «Elegibilidad y demanda», R2, DM-01 y DM-03 a DM-07;
 * tarea 5.3). Versión v2 (confirmada el 17 de noviembre de 2026):
 *
 *     inscritos = Σ por tramo de distancia (elegibles del tramo × (1 − no inscripción del tramo))
 *     repetidores esperados = cursando hoy × tasa de reprobación × (1 − no inscripción de la materia)
 *     esperados = inscritos + repetidores esperados + nuevo ingreso
 *     grupos sugeridos = techo(esperados / tamaño objetivo)
 *
 * Los tramos (GapBucket) son la distancia entre el semestre de la materia y el del alumno; un tramo sin tasa propia usa
 * la de la materia en todos los tramos, y sin tramos todos los elegibles usan esa. Los inscritos y los repetidores se
 * redondean al entero más cercano, cada uno por su lado. El nuevo ingreso solo entra en primer semestre del plan
 * vigente (el ingreso histórico de la carrera en el campus). Sin ninguna tasa, se espera a todos los elegibles, como en
 * R1, y ningún repetidor. El tamaño objetivo es el normal o el de laboratorio (`demand.target_group_size`,
 * SuggestedGroups; bastan los dos, confirmado el 16 de noviembre de 2026).
 *
 * Es pura, sin base de datos: así se puede cambiar y versionar sin tocar el job ni las pantallas. Cada cambio de la
 * fórmula sube VERSION, que se guarda en cada fila (`formula_version`). v1 sumaba a todos los repetidores sin la no
 * inscripción y usaba una sola no inscripción por materia.
 */
final readonly class DemandForecaster
{
    public const string VERSION = 'v2';

    public function __construct(private SuggestedGroups $groupSizes = new SuggestedGroups) {}

    public function forecast(ForecastInput $input): ForecastResult
    {
        $rates = $input->rates;
        $enrolling = $this->enrolling($input);
        $repeaters = $rates === null ? 0 : (int) round($input->currentStudents * $rates->failure * (1 - $rates->nonEnrollment));
        $expected = (int) round($enrolling) + $repeaters + ($input->newStudents ?? 0);

        return new ForecastResult(
            expectedRepeaters: $repeaters,
            expectedStudents: $expected,
            suggestedGroups: $this->groupSizes->for($expected, $input->needsLab),
            method: $this->methodFor($input),
            formulaVersion: self::VERSION,
            nonEnrollmentRate: $this->effectiveNonEnrollment($input, $enrolling),
        );
    }

    /**
     * Los elegibles que se espera que se inscriban, sin redondear.
     */
    private function enrolling(ForecastInput $input): float
    {
        $rates = $input->rates;

        if ($rates === null) {
            return $input->eligibleStudents;
        }

        if ($input->eligibleByGap === []) {
            return $input->eligibleStudents * (1 - $rates->nonEnrollment);
        }

        $enrolling = 0.0;

        foreach ($input->eligibleByGap as $bucket => $eligible) {
            $enrolling += $eligible * (1 - $rates->nonEnrollmentFor(GapBucket::from((string) $bucket)));
        }

        return $enrolling;
    }

    private function effectiveNonEnrollment(ForecastInput $input, float $enrolling): ?float
    {
        if ($input->rates === null) {
            return null;
        }

        if ($input->eligibleStudents === 0) {
            return $input->rates->nonEnrollment;
        }

        return max(0.0, min(1.0, 1 - $enrolling / $input->eligibleStudents));
    }

    private function methodFor(ForecastInput $input): ForecastMethod
    {
        return match (true) {
            $input->newStudents !== null => ForecastMethod::FirstSemester,
            $input->rates === null => ForecastMethod::NoHistory,
            $input->rates->fromDepartment => ForecastMethod::DepartmentFallback,
            default => ForecastMethod::Formula,
        };
    }
}
