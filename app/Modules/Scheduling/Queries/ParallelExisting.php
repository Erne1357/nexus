<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Un grupo suelto (sin paquete) que ya existe de una materia y campus, como lo muestra la pestaña «Paralelos» (tarea 8.8):
 * su clave, su carrera dueña, su capacidad, su docente (el nombre, o null), sus sesiones y minutos colocados, sus cupos
 * por carrera (`carrera => lugares`) y, si un módulo de la derecha no deja cancelarlo (una solicitud de cambio abierta,
 * GroupsCancelling), su motivo (`vetoed`).
 */
final readonly class ParallelExisting
{
    /**
     * @param  array<string, int>  $quotas
     */
    public function __construct(
        public string $id,
        public string $code,
        public string $ownerCareerId,
        public int $capacity,
        public ?string $teacher,
        public int $sessions,
        public int $minutes,
        public array $quotas,
        public ?string $vetoed = null,
    ) {}

    public function hasTeacher(): bool
    {
        return $this->teacher !== null;
    }

    /**
     * Si no se puede cancelar: tiene docente o una solicitud de cambio abierta.
     */
    public function isLocked(): bool
    {
        return $this->hasTeacher() || $this->vetoed !== null;
    }

    /**
     * Por qué no se cancela, con la clave de su carrera dueña para el mensaje; null si se puede cancelar.
     */
    public function lockReason(?string $ownerCode): ?string
    {
        return match (true) {
            $this->hasTeacher() => $ownerCode === null
                ? "El grupo {$this->code} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de su carrera o quítale primero el docente."
                : "El grupo {$this->code} de {$ownerCode} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de {$ownerCode} o quítale primero el docente.",
            $this->vetoed !== null => $this->vetoed,
            default => null,
        };
    }

    /**
     * Lo que pide ParallelPlacement de cada grupo.
     *
     * @return array{id: string, owner: string, locked: bool, minutes: int}
     */
    public function forPlacement(): array
    {
        return ['id' => $this->id, 'owner' => $this->ownerCareerId, 'locked' => $this->isLocked(), 'minutes' => $this->minutes];
    }
}
