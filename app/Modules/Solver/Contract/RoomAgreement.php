<?php

namespace App\Modules\Solver\Contract;

/** Un préstamo aceptado de un salón a otro departamento en un bloque (HC-10). */
final readonly class RoomAgreement
{
    public function __construct(
        public string $roomId,
        public string $toDepartmentId,
        public int $day,
        public string $startsAt,
        public string $endsAt,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $roomId = ContractFields::string($data, 'room_id', $path);
        $toDepartmentId = ContractFields::string($data, 'to_department_id', $path);
        $day = ContractFields::int($data, 'day', $path, 1, 6);
        $startsAt = ContractFields::time($data, 'starts_at', $path);
        $endsAt = ContractFields::time($data, 'ends_at', $path);
        ContractFields::assertOrdered($startsAt, $endsAt, $path);

        return new self($roomId, $toDepartmentId, $day, $startsAt, $endsAt);
    }

    /**
     * @return array{room_id: string, to_department_id: string, day: int, starts_at: string, ends_at: string}
     */
    public function toArray(): array
    {
        return [
            'room_id' => $this->roomId,
            'to_department_id' => $this->toDepartmentId,
            'day' => $this->day,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
        ];
    }
}
