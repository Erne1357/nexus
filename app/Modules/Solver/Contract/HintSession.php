<?php

namespace App\Modules\Solver\Contract;

/**
 * Una sesión de la solución inicial sugerida (`hint_sessions[]`): el grupo, el día, la hora y el salón. Es una pista que
 * el motor puede ignorar; no cambia el problema.
 */
final readonly class HintSession
{
    public function __construct(
        public string $groupId,
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public SessionKind $kind,
        public ?string $roomId = null,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $groupId = ContractFields::string($data, 'group_id', $path);
        $slot = SessionSlot::fromArray($data, $path);

        return new self($groupId, $slot->day, $slot->startsAt, $slot->endsAt, $slot->kind, $slot->roomId);
    }

    /**
     * @return array{group_id: string, day: int, starts_at: string, ends_at: string, room_id?: string, kind: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'group_id' => $this->groupId,
            'day' => $this->day,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'room_id' => $this->roomId,
            'kind' => $this->kind->value,
        ], fn (mixed $v): bool => $v !== null);
    }
}
