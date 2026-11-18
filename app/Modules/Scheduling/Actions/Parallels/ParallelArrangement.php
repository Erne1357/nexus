<?php

namespace App\Modules\Scheduling\Actions\Parallels;

use App\Modules\Scheduling\Models\Group;

/**
 * Lo que dejó armar los paralelos de una materia en un campus (ArmParallelGroups), para que la pantalla lo diga:
 * - `kept`: los grupos que se quedaron, con sus cupos antes y después (`carrera => lugares`);
 * - `created`: los grupos que nacieron, sueltos y sin horario;
 * - `cancelled`: los que se cancelaron, con cuántas sesiones y minutos de horario perdieron.
 */
final readonly class ParallelArrangement
{
    /**
     * @param  list<array{group: Group, before: array<string, int>, after: array<string, int>}>  $kept
     * @param  list<Group>  $created
     * @param  list<array{group: Group, sessions: int, minutes: int}>  $cancelled
     */
    public function __construct(
        public array $kept,
        public array $created,
        public array $cancelled,
    ) {}

    /**
     * Si cambió algo: un cupo, un grupo nuevo o uno cancelado.
     */
    public function changed(): bool
    {
        return $this->created !== [] || $this->cancelled !== []
            || array_any($this->kept, fn (array $row): bool => $row['before'] != $row['after']);
    }

    /**
     * Los grupos cancelados que tenían horario: sus sesiones se perdieron.
     *
     * @return list<array{group: Group, sessions: int, minutes: int}>
     */
    public function lostSchedules(): array
    {
        return array_values(array_filter($this->cancelled, fn (array $row): bool => $row['sessions'] > 0));
    }
}
