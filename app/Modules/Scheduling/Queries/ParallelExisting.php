<?php

namespace App\Modules\Scheduling\Queries;

/**
 * Un grupo suelto (sin paquete) que ya existe de una materia y campus, como lo muestra la pestaña «Paralelos» (tarea 8.8):
 * su clave, su carrera dueña, su capacidad, su docente (el nombre, o null), sus sesiones y minutos colocados, y sus cupos
 * por carrera (`carrera => lugares`).
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
    ) {}

    public function hasTeacher(): bool
    {
        return $this->teacher !== null;
    }

    /**
     * Lo que pide ParallelPlacement de cada grupo.
     *
     * @return array{id: string, owner: string, teacher: bool, minutes: int}
     */
    public function forPlacement(): array
    {
        return ['id' => $this->id, 'owner' => $this->ownerCareerId, 'teacher' => $this->hasTeacher(), 'minutes' => $this->minutes];
    }
}
