<?php

namespace App\Modules\Students\Events;

use Illuminate\Foundation\Events\Dispatchable;

/**
 * Se recalculó `student_progress` (RefreshStudentProgress): de todos los alumnos, o solo de los de un plan. Sale al
 * terminar, ya con la transacción del recálculo confirmada. Lo escucha Demand para volver a proyectar los periodos
 * abiertos con el kárdex nuevo (tarea 5.4): Demand no puede escuchar a Imports, que está a su derecha en el mapa, así
 * que la señal de «llegó kárdex nuevo» es este recálculo, que las plantillas de importación disparan al aplicar un lote.
 */
final class StudentProgressRefreshed
{
    use Dispatchable;

    public function __construct(public readonly ?string $studyPlanId = null) {}
}
