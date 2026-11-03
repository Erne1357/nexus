<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\AssignmentStatus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\AssignmentTimeline;
use Carbon\CarbonImmutable;

/**
 * Lo que la ficha de una persona en «Personas y puestos» muestra, armado por PersonRecordQuery: sus nombramientos con
 * su estado, quién los terminó y por qué no se pueden terminar aquí; las cifras; la línea de vigencias y la bitácora
 * de sus puestos.
 */
final readonly class PersonRecord
{
    /**
     * @param  list<array{assignment: RoleAssignment, label: AssignmentLabel, status: AssignmentStatus, daysLeft: ?int, endedBy: ?array{name: string, on: CarbonImmutable}, endBlocker: ?string}>  $assignments  vigentes, programados y terminados, en ese orden
     * @param  int  $current  los vigentes
     * @param  int  $past  los terminados
     * @param  CarbonImmutable|null  $since  el inicio del nombramiento más antiguo
     * @param  array{on: CarbonImmutable, summary: string}|null  $nextExpiry  el vigente que termina primero
     * @param  array{on: CarbonImmutable, by: ?string}|null  $lastAppointment
     * @param  list<array{on: CarbonImmutable, by: ?string, description: string}>  $log  las 10 últimas entradas sobre sus nombramientos
     */
    public function __construct(
        public User $person,
        public array $assignments,
        public int $current,
        public int $past,
        public ?CarbonImmutable $since,
        public ?array $nextExpiry,
        public AssignmentTimeline $timeline,
        public ?array $lastAppointment,
        public array $log,
    ) {}

    /**
     * Las iniciales del avatar: la de las dos primeras palabras del nombre.
     */
    public function initials(): string
    {
        $words = preg_split('/\s+/u', trim($this->person->name), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        return mb_strtoupper(implode('', array_map(fn (string $word): string => mb_substr($word, 0, 1), array_slice($words, 0, 2))));
    }
}
