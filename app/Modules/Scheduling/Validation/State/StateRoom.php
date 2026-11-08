<?php

namespace App\Modules\Scheduling\Validation\State;

use App\Modules\Facilities\Enums\RoomType;

/**
 * Un salón en el estado del validador: capacidad (HC-05), tipo (HC-06) y departamento dueño (HC-10).
 */
final readonly class StateRoom
{
    public function __construct(
        public string $id,
        public string $campusId,
        public string $code,
        public int $capacity,
        public RoomType $type = RoomType::Aula,
        public ?string $departmentId = null,
        public bool $isActive = true,
    ) {}
}
