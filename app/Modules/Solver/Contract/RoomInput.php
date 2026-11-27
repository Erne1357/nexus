<?php

namespace App\Modules\Solver\Contract;

/** Un salón con sus bloqueos y las sesiones de grupos fuera del alcance que ya lo ocupan. */
final readonly class RoomInput
{
    /**
     * @param  list<TimeSlot>  $blocks
     * @param  list<TimeSlot>  $busy
     */
    public function __construct(
        public string $id,
        public string $campusCode,
        public string $type,
        public int $capacity,
        public string $buildingId,
        public ?string $ownerDepartmentId,
        public array $blocks,
        public array $busy,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        return new self(
            ContractFields::string($data, 'id', $path),
            ContractFields::string($data, 'campus_code', $path),
            ContractFields::string($data, 'type', $path),
            ContractFields::int($data, 'capacity', $path, 1),
            ContractFields::string($data, 'building_id', $path),
            ContractFields::string($data, 'owner_department_id', $path, false),
            ContractFields::objects($data, 'blocks', $path, TimeSlot::class, false),
            ContractFields::objects($data, 'busy', $path, TimeSlot::class, false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $slots = fn (array $list): array => array_map(fn (TimeSlot $s): array => $s->toArray(), $list);

        return array_filter([
            'id' => $this->id,
            'campus_code' => $this->campusCode,
            'type' => $this->type,
            'capacity' => $this->capacity,
            'building_id' => $this->buildingId,
            'owner_department_id' => $this->ownerDepartmentId,
            'blocks' => $slots($this->blocks),
            'busy' => $slots($this->busy),
        ], fn (mixed $v): bool => $v !== null);
    }
}
