<?php

namespace App\Modules\Scheduling\Validation\State;

/**
 * Un docente en el estado del validador: horas base (las de sus plazas) y externas del periodo, sus horas frente a
 * grupo (PL-01 con el respaldo del CSV de la selección; con ellas mide HC-08 desde la tarea 1.50), sus bloques no
 * disponibles (HC-09), cada uno como [día, inicio, fin] en minutos, y sus horas autorizadas del periodo (SEL-12; null si
 * no tiene).
 *
 * `administrativeHours` (PL-03) es la suma de las horas de sus plazas de apoyo y asistencia vigentes, o null si no tiene
 * ninguna: con ellas no lleva banda de ±2, sino el tope de 42 h entre clases, externas y estas horas, y lo que pase es de
 * honorarios.
 *
 * `engineLimit` solo lo lleva la foto que se arma del contrato del motor (ContractState): es el límite de HC-08 que ya
 * trae la entrada (lo que le queda más sus grupos fijos) y con él HC-08 es choque duro, porque la propuesta automática
 * no sobrecarga a nadie sin oficio (1.50, supuesto por confirmar). En la foto de la base es null y HC-08 es aviso.
 */
final readonly class StateTeacher
{
    /**
     * @param  list<array{int, int, int}>  $unavailable
     */
    public function __construct(
        public string $id,
        public string $name,
        public int $baseHours = 0,
        public int $externalHours = 0,
        public array $unavailable = [],
        public bool $isHonorarios = false,
        public ?int $allowanceHours = null,
        public int $teachingHours = 0,
        public ?int $engineLimit = null,
        public ?int $administrativeHours = null,
    ) {}
}
