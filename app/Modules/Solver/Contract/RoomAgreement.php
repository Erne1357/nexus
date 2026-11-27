<?php

namespace App\Modules\Solver\Contract;

/**
 * Un préstamo aceptado de un salón a otro departamento en un bloque (HC-10). `group_id` es opcional: el grupo para el que
 * se pidió, como lo revisa el validador (RoomLoan, por grupo), y solo a él le sirve. Sin él, el préstamo cubre a todos
 * los grupos del departamento (las entradas de antes). Agregarlo no cambia la versión del contrato.
 */
final readonly class RoomAgreement
{
    public function __construct(
        public string $roomId,
        public string $toDepartmentId,
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public ?string $groupId = null,
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

        return new self($roomId, $toDepartmentId, $day, $startsAt, $endsAt, ContractFields::string($data, 'group_id', $path, false));
    }

    /**
     * Sin `group_id` cuando es del departamento, para que una entrada de antes vuelva a su JSON exacto.
     *
     * @return array{room_id: string, to_department_id: string, day: int, starts_at: string, ends_at: string, group_id?: string}
     */
    public function toArray(): array
    {
        return [
            'room_id' => $this->roomId,
            'to_department_id' => $this->toDepartmentId,
            'day' => $this->day,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            ...($this->groupId === null ? [] : ['group_id' => $this->groupId]),
        ];
    }
}
