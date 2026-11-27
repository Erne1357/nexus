<?php

namespace App\Modules\Faculty\Rules;

use App\Modules\Faculty\Enums\PositionKind;

/**
 * PL-01 y PL-03. Horas frente a grupo que le tocan a un docente según sus plazas vigentes (Reglamento Interior de Trabajo del
 * Personal Docente de los Institutos Tecnológicos, arts. 140, 143, 144, 146, 149 y 155; ver
 * docs/dominio/lineamientos-carga-docente.md). Puro: no lee la base.
 *
 * - Plaza de carrera (tiempo completo, tres cuartos, medio tiempo): la tabla de su categoría (`E3817`: jornada 8 = TC,
 *   7 = ¾, 6 = ½; categoría = los dos últimos dígitos). Profesor de carrera asociado (07, 09, 11) y titular (13, 15,
 *   17); técnico docente (37, 39, 41, 43, 45, 75); investigador titular (59, 61, 63). Categoría desconocida o sin
 *   categoría: titular (supuesto por confirmar). El asistente no tiene clave en el catálogo de claves.
 * - Asignatura sola: todas sus horas (arts. 140 y 155). Con plaza de carrera además, las de asignatura se miden por la
 *   tabla del art. 149 (1→1, 2→1, 3→2 … 9→5; de 10 en adelante, la mitad hacia arriba: supuesto por confirmar).
 * - Apoyo y asistencia: 0 en esta cuenta (no es plaza docente). Quien tiene una es «de plaza administrativa» y no se
 *   mide con esta regla ni con la banda de ±2, sino con PL-03 (administrativeHours, tope de 42 h; confirmado el 27 de
 *   noviembre de 2026). Honorarios: 0, que es lo que ya valían sus horas base; el tope de `staffing.honorarios_max_hours`
 *   lo mide HC-08, no esta regla (supuesto por confirmar).
 * - El margen de ±2 (art. 147, MARGIN) lo aplican la banda de la selección (LoadBand, SEL-05) y HC-08 (1.50) a quien no
 *   es de plaza administrativa.
 */
final class TeachingHours
{
    /** Art. 147: la carga frente a grupo puede variar dos horas arriba o abajo (la banda de SEL-05 y el tope de HC-08). */
    public const int MARGIN = 2;

    /** PL-03 y HC-22: lo más que pueden sumar clases, horas externas y horas administrativas de quien tiene plaza administrativa (límite duro). */
    public const int ADMINISTRATIVE_MAX = 42;

    /** PL-04 (tarea 1.81, confirmado el 27 de noviembre de 2026): lo más que puede sumar quien tiene plaza docente entre las horas de su plaza y sus horas extra (honorarios): TC de 40 h con hasta 4 h de honorarios (límite duro, HC-22). */
    public const int MAX_WITH_HONORARIOS = 44;

    /** Horas de carrera por familia y jornada [TC, ¾, ½] (arts. 143, 144 y 146). */
    private const array CAREER = [
        'titular' => ['tiempo_completo' => 22, 'tres_cuartos' => 16, 'medio_tiempo' => 12],
        'asociado' => ['tiempo_completo' => 24, 'tres_cuartos' => 18, 'medio_tiempo' => 12],
        'asistente' => ['tiempo_completo' => 26, 'tres_cuartos' => 20, 'medio_tiempo' => 14],
        'investigador' => ['tiempo_completo' => 12, 'tres_cuartos' => 10, 'medio_tiempo' => 6],
        'tecnico' => ['tiempo_completo' => 36, 'tres_cuartos' => 28, 'medio_tiempo' => 18],
    ];

    /** Familia por los dos últimos dígitos de la clave. */
    private const array FAMILY = [
        '07' => 'asociado', '09' => 'asociado', '11' => 'asociado',
        '13' => 'titular', '15' => 'titular', '17' => 'titular',
        '37' => 'tecnico', '39' => 'tecnico', '41' => 'tecnico', '43' => 'tecnico', '45' => 'tecnico', '75' => 'tecnico',
        '59' => 'investigador', '61' => 'investigador', '63' => 'investigador',
    ];

    /** Art. 149: horas frente a grupo de las horas de asignatura de quien tiene plaza de carrera, de 1 a 9. */
    private const array ASSIGNMENT_ON_CAREER = [0 => 0, 1 => 1, 2 => 1, 3 => 2, 4 => 2, 5 => 3, 6 => 3, 7 => 4, 8 => 4, 9 => 5];

    /**
     * @param  iterable<array{kind: PositionKind, category: ?string, base_hours: int}>  $positions  las plazas vigentes del docente
     */
    public static function forPositions(iterable $positions): int
    {
        $career = 0;
        $assignment = 0;
        $hasCareer = false;

        foreach ($positions as $position) {
            $kind = $position['kind'];

            if (in_array($kind, [PositionKind::TiempoCompleto, PositionKind::TresCuartos, PositionKind::MedioTiempo], true)) {
                $hasCareer = true;
                $career += self::careerHours($kind, $position['category'] ?? null);
            } elseif ($kind === PositionKind::Asignatura) {
                $assignment += $position['base_hours'];
            }
        }

        return $career + ($hasCareer ? self::onCareer($assignment) : $assignment);
    }

    /**
     * PL-03. Las horas administrativas del docente: la suma de las horas base de sus plazas vigentes de apoyo y
     * asistencia, o null si no tiene ninguna (no es «de plaza administrativa»). Un docente con una de estas plazas no
     * lleva banda de ±2: su límite es ADMINISTRATIVE_MAX entre clases, horas externas y estas horas.
     *
     * @param  iterable<array{kind: PositionKind, category?: ?string, base_hours: int}>  $positions
     */
    public static function administrativeHours(iterable $positions): ?int
    {
        $hours = null;

        foreach ($positions as $position) {
            if ($position['kind'] === PositionKind::Apoyo) {
                $hours = ($hours ?? 0) + $position['base_hours'];
            }
        }

        return $hours;
    }

    /**
     * Las horas frente a grupo de quien no tiene plazas en Nexus, a partir de las horas del CSV de la selección (SEL-06):
     * 40 es 22, 30 es 16, 20 es 12 y cualquier otro valor se queda igual (supuesto por confirmar).
     */
    public static function fromCsvHours(int $hours): int
    {
        return match ($hours) {
            40 => 22,
            30 => 16,
            20 => 12,
            default => $hours,
        };
    }

    /**
     * PL-04. Las horas de plaza que corresponden a unas horas frente a grupo que vienen del CSV de la selección: la
     * operación inversa de fromCsvHours (22 es 40, 16 es 30, 12 es 20; otro valor se queda igual; supuesto por confirmar).
     * Sirve para medir el tope con honorarios de quien no tiene plazas en Nexus pero sí renglón en el CSV.
     */
    public static function plazaFromTeaching(int $teaching): int
    {
        return match ($teaching) {
            22 => 40,
            16 => 30,
            12 => 20,
            default => $teaching,
        };
    }

    /**
     * Las horas frente a grupo que se le piden al docente: las de sus plazas en Nexus (forPositions) y, si no tiene o
     * dan 0 (solo apoyo u honorarios), las de su renglón del CSV de la selección (fromCsvHours; supuesto por
     * confirmar). La comparten la selección (SEL-05) y HC-08 (1.50).
     */
    public static function target(int $fromPositions, int $csvHours): int
    {
        return $fromPositions > 0 ? $fromPositions : self::fromCsvHours($csvHours);
    }

    /** Art. 149 para n horas de asignatura sobre una plaza de carrera. */
    public static function onCareer(int $hours): int
    {
        return $hours <= 9 ? self::ASSIGNMENT_ON_CAREER[max(0, $hours)] : intdiv($hours + 1, 2);
    }

    private static function careerHours(PositionKind $kind, ?string $category): int
    {
        $family = self::FAMILY[substr((string) $category, -2)] ?? 'titular';

        return self::CAREER[$family][$kind->value];
    }
}
