<?php

namespace App\Modules\Solver\Contract;

/** Una sesión propuesta por el motor. `changed` indica si difiere de lo que había en la entrada. */
final readonly class ProposedSession
{
    public function __construct(
        public string $groupId,
        public int $day,
        public string $startsAt,
        public string $endsAt,
        public ?string $roomId,
        public SessionKind $kind,
        public bool $changed,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $groupId = ContractFields::string($data, 'group_id', $path);
        $day = ContractFields::int($data, 'day', $path, 1, 6);
        $startsAt = ContractFields::time($data, 'starts_at', $path);
        $endsAt = ContractFields::time($data, 'ends_at', $path);
        ContractFields::assertOrdered($startsAt, $endsAt, $path);

        return new self(
            $groupId,
            $day,
            $startsAt,
            $endsAt,
            ContractFields::string($data, 'room_id', $path, false),
            ContractFields::enum($data, 'kind', $path, SessionKind::class),
            ContractFields::bool($data, 'changed', $path),
        );
    }

    /**
     * @return array{group_id: string, day: int, starts_at: string, ends_at: string, room_id?: string, kind: string, changed: bool}
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
            'changed' => $this->changed,
        ], fn (mixed $v): bool => $v !== null);
    }
}
