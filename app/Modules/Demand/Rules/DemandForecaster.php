<?php

namespace App\Modules\Demand\Rules;

use App\Modules\Demand\Enums\ForecastMethod;

/**
 * La fórmula de la proyección de demanda (reglas-de-negocio.md, «Elegibilidad y demanda», R2; tarea 5.3):
 *
 *     esperados = elegibles × (1 − tasa de no inscripción) + repetidores esperados + nuevo ingreso
 *     repetidores esperados = cursando hoy × tasa de reprobación
 *     grupos sugeridos = techo(esperados / tamaño objetivo)
 *
 * Los inscritos y los repetidores se redondean al entero más cercano, cada uno por su lado. El nuevo ingreso solo
 * entra en primer semestre (el ingreso histórico de la carrera en el campus, confirmado el 16 de noviembre de 2026).
 * Sin ninguna tasa, se espera a todos los elegibles, como en R1. El tamaño objetivo es el normal o el de laboratorio
 * (`demand.target_group_size`, SuggestedGroups; bastan los dos, confirmado el 16 de noviembre de 2026).
 *
 * Es pura, sin base de datos: así se puede cambiar y versionar sin tocar el job ni las pantallas. Cada cambio de la
 * fórmula sube VERSION, que se guarda en cada fila (`formula_version`).
 */
final readonly class DemandForecaster
{
    public const string VERSION = 'v1';

    public function __construct(private SuggestedGroups $groupSizes = new SuggestedGroups) {}

    public function forecast(ForecastInput $input): ForecastResult
    {
        $rates = $input->rates;
        $enrolling = (int) round($input->eligibleStudents * (1 - ($rates?->nonEnrollment ?? 0.0)));
        $repeaters = (int) round($input->currentStudents * ($rates?->failure ?? 0.0));
        $expected = $enrolling + $repeaters + ($input->newStudents ?? 0);

        return new ForecastResult(
            expectedRepeaters: $repeaters,
            expectedStudents: $expected,
            suggestedGroups: $this->groupSizes->for($expected, $input->needsLab),
            method: $this->methodFor($input),
            formulaVersion: self::VERSION,
        );
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
