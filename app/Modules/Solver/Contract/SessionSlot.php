<?php

namespace App\Modules\Solver\Contract;

/** Una sesión de un grupo (en la entrada, una que no se mueve). `roomId` es nulo si la modalidad no usa salones. */
final readonly class SessionSlot
{
    public function __construct(
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
        $day = ContractFields::int($data, 'day', $path, 1, 6);
        $startsAt = ContractFields::time($data, 'starts_at', $path);
        $endsAt = ContractFields::time($data, 'ends_at', $path);
        ContractFields::assertOrdered($startsAt, $endsAt, $path);

        return new self(
            $day,
            $startsAt,
            $endsAt,
            ContractFields::enum($data, 'kind', $path, SessionKind::class),
            ContractFields::string($data, 'room_id', $path, false),
        );
    }

    /**
     * @return array{day: int, starts_at: string, ends_at: string, room_id?: string, kind: string}
     */
    public function toArray(): array
    {
        return array_filter([
            'day' => $this->day,
            'starts_at' => $this->startsAt,
            'ends_at' => $this->endsAt,
            'room_id' => $this->roomId,
            'kind' => $this->kind->value,
        ], fn (mixed $v): bool => $v !== null);
    }
}
