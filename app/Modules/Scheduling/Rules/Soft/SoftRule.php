<?php

namespace App\Modules\Scheduling\Rules\Soft;

use App\Modules\Scheduling\Validation\ScheduleState;
use App\Modules\Scheduling\Validation\ValidationScope;

/**
 * Una regla blanda (SC-01 a SC-09) de docs/dominio/reglas-de-negocio.md, «Restricciones blandas», para el puntaje del
 * motor de horarios (tarea 6.5). Es pura, como las duras de Rules/Constraints: lee la foto y lo que la foto no trae
 * (SoftContext), y nunca la base. Cuenta su penalización sin peso, en la unidad que dice su docblock, sobre lo que toca
 * al alcance; SoftScorer la multiplica por su peso.
 */
interface SoftRule
{
    /**
     * El id de la regla: `SC-01`.
     */
    public function rule(): string;

    /**
     * La penalización sin peso de lo que toca al alcance; cero si no hay nada que penalizar.
     */
    public function penalty(ScheduleState $state, ValidationScope $scope, SoftContext $context): int;
}
