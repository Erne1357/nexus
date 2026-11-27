<?php

namespace App\Modules\Faculty\Events;

use Illuminate\Contracts\Events\ShouldDispatchAfterCommit;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Cambiaron las horas frente a grupo de unos docentes en un periodo por otra vía que sus plazas: el CSV de criterios de
 * la orden de selección, que es su respaldo cuando las plazas no dan horas (SelectionCsvHours, PL-01). Cambia la banda
 * de HC-08 (tarea 1.50), así que Scheduling recalcula los choques guardados y Staffing la carga por docente y el
 * ranking. Lo lanza Staffing al importar el CSV, resolver un renglón y cancelar una selección. Sale después del commit.
 */
final class TeachingHoursChanged implements ShouldDispatchAfterCommit
{
    use Dispatchable;

    /**
     * @param  list<string>  $teacherIds
     */
    public function __construct(public string $termId, public array $teacherIds) {}

    /**
     * Lo lanza para esos docentes, sin repetir; sin docentes no hay nada que avisar.
     *
     * @param  array<int, string>  $teacherIds
     */
    public static function dispatchFor(string $termId, array $teacherIds): void
    {
        $teacherIds = array_values(array_unique(array_map('strval', $teacherIds)));

        if ($teacherIds !== []) {
            event(new self($termId, $teacherIds));
        }
    }
}
