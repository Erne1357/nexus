<?php

namespace App\Modules\Scheduling\Rules\Constraints;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;
use App\Modules\Scheduling\Validation\Violation;

/**
 * Una regla dura (HC-01 a HC-13) o blanda (SC) de docs/dominio/reglas-de-negocio.md, aislada para probarse sola y
 * reutilizarse en el motor de R2 (tarea 3.2). Es pura: lee el ScheduleState y nunca la base. Revisa lo que toca al
 * alcance y reporta cada choque en cada grupo involucrado, con el mensaje en español. ScheduleValidator las junta.
 */
interface Constraint
{
    /**
     * El id de la regla: `HC-01`.
     */
    public function rule(): string;

    /**
     * @return iterable<Violation>
     */
    public function check(ScheduleState $state, ValidationScope $scope): iterable;
}
