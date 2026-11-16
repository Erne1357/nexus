<?php

namespace App\Modules\Demand\Rules;

use InvalidArgumentException;

/**
 * Lo que necesita la fórmula para una materia de una carrera en un campus (tarea 5.3):
 *
 * - `eligibleStudents`: los elegibles proyectados al inicio del periodo, sin quienes cursan hoy la materia. Ya incluye
 *   a quienes cursan hoy su seriación, contados como si la aprobaran (confirmado el 16 de noviembre de 2026).
 * - `pendingPrerequisiteStudents`: cuántos de esos elegibles dependen de aprobar algo que cursan hoy. Solo informa: se
 *   guarda aparte para verlo y, más adelante, para un escenario que descuente a los que no aprueban.
 * - `currentStudents`: quienes cursan hoy la materia, la base de los repetidores esperados.
 * - `rates`: las tasas históricas, o null si no hay ninguna (ni de la materia ni de su departamento).
 * - `newStudents`: el nuevo ingreso histórico en una materia de primer semestre; null en las demás.
 * - `needsLab`: si la materia lleva laboratorio, que decide el tamaño objetivo.
 */
final readonly class ForecastInput
{
    public function __construct(
        public int $eligibleStudents,
        public int $pendingPrerequisiteStudents,
        public int $currentStudents,
        public ?SubjectRates $rates,
        public ?int $newStudents,
        public bool $needsLab,
    ) {
        foreach (['eligibleStudents' => $eligibleStudents, 'pendingPrerequisiteStudents' => $pendingPrerequisiteStudents, 'currentStudents' => $currentStudents, 'newStudents' => $newStudents ?? 0] as $name => $count) {
            if ($count < 0) {
                throw new InvalidArgumentException("{$name} no es negativo: {$count}.");
            }
        }

        if ($pendingPrerequisiteStudents > $eligibleStudents) {
            throw new InvalidArgumentException("Los que cursan la seriación ({$pendingPrerequisiteStudents}) son parte de los elegibles ({$eligibleStudents}).");
        }
    }
}
