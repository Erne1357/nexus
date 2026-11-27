<?php

namespace App\Modules\Solver\Contract;

/** La rejilla de bloques de un campus: ventana del día, duración del bloque y días hábiles. */
final readonly class CampusGrid
{
    /**
     * @param  list<int>  $days  De 1 (lunes) a 6 (sábado).
     */
    public function __construct(
        public string $code,
        public int $blockMinutes,
        public string $dayStart,
        public string $dayEnd,
        public array $days,
        public bool $usesRooms,
    ) {}

    /**
     * @param  array<string, mixed>  $data
     */
    public static function fromArray(array $data, string $path = ''): self
    {
        $code = ContractFields::string($data, 'code', $path);
        $blockMinutes = ContractFields::int($data, 'block_minutes', $path, 1);
        $dayStart = ContractFields::time($data, 'day_start', $path);
        $dayEnd = ContractFields::time($data, 'day_end', $path);
        ContractFields::assertOrdered($dayStart, $dayEnd, $path, 'day_end');
        $minutes = fn (string $time): int => ((int) substr($time, 0, 2)) * 60 + (int) substr($time, 3, 2);
        if (($minutes($dayEnd) - $minutes($dayStart)) % $blockMinutes !== 0) {
            throw ContractException::at(ContractFields::path($path, 'day_end'), "la jornada de {$dayStart} a {$dayEnd} no se divide en bloques de {$blockMinutes} minutos");
        }

        $days = [];
        foreach (ContractFields::list($data, 'days', $path) as $i => $day) {
            $at = ContractFields::path(ContractFields::path($path, 'days'), $i);
            if (! is_int($day) || $day < 1 || $day > 6) {
                throw ContractException::at($at, 'debe ser un entero entre 1 y 6');
            }
            $days[] = $day;
        }
        if ($days === []) {
            throw ContractException::at(ContractFields::path($path, 'days'), 'debe tener al menos un día');
        }

        return new self($code, $blockMinutes, $dayStart, $dayEnd, $days, ContractFields::bool($data, 'uses_rooms', $path));
    }

    /**
     * @return array{code: string, block_minutes: int, day_start: string, day_end: string, days: list<int>, uses_rooms: bool}
     */
    public function toArray(): array
    {
        return [
            'code' => $this->code,
            'block_minutes' => $this->blockMinutes,
            'day_start' => $this->dayStart,
            'day_end' => $this->dayEnd,
            'days' => $this->days,
            'uses_rooms' => $this->usesRooms,
        ];
    }
}
