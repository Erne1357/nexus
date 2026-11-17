<?php

namespace App\Modules\Solver\Contract;

/** Un docente referido por los grupos, con su disponibilidad y lo que ya tiene ocupado fuera de la corrida. */
final readonly class TeacherInput
{
    /**
     * @param  list<TimeSlot>  $unavailable
     * @param  list<TimeSlot>  $preferred
     * @param  list<TimeSlot>  $busy  Con `campus_code`.
     */
    public function __construct(
        public string $id,
        public int $remainingHours,
        public bool $honorarios,
        public array $unavailable,
        public array $preferred,
        public array $busy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'id', $path),
            ContractFields::int($data, 'remaining_hours', $path, 0),
            ContractFields::bool($data, 'honorarios', $path),
            ContractFields::objects($data, 'unavailable', $path, TimeSlot::class, false),
            ContractFields::objects($data, 'preferred', $path, TimeSlot::class, false),
            ContractFields::objects($data, 'busy', $path, TimeSlot::class, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $slots = fn (array $list): array => array_map(fn (TimeSlot $s): array => $s->toArray(), $list);

        return [
            'id' => $this->id,
            'remaining_hours' => $this->remainingHours,
            'honorarios' => $this->honorarios,
            'unavailable' => $slots($this->unavailable),
            'preferred' => $slots($this->preferred),
            'busy' => $slots($this->busy),
        ];
    }
}
