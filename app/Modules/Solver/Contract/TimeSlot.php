<?php

namespace App\Modules\Solver\Contract;

/**
 * Un bloque de tiempo en un día: no disponible, preferido, ocupado o bloqueado. `campusCode` solo va en las ocupaciones
 * de un docente fuera de la corrida (el motor lo necesita para el traslado, SC-09).
 */
final readonly class TimeSlot
{
    public function __construct(
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public ?string $campusCode = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $startsAt = ContractFields::time($data, 'starts_at', $path);
        $endsAt = ContractFields::time($data, 'ends_at', $path);
        $day = ContractFields::int($data, 'day', $path, 1, 6);
        ContractFields::assertOrdered($startsAt, $endsAt, $path);

        return new self($day, $startsAt, $endsAt, ContractFields::string($data, 'campus_code', $path, false));
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, campus_code?: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'day' => $this->day,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'campus_code' => $this->campusCode,
        ], fn (mixed $v): bool => $v !== null);
    }
}
