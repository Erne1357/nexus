<?php

namespace App\Modules\Scheduling\Queries;

use App\Modules\Scheduling\Actions\Parallels\ArmParallelGroups;
use App\Modules\Scheduling\Rules\ParallelPlacement;
use App\Modules\Scheduling\Rules\QuotaRules;

/**
 * El armado que edita el jefe en el drawer de «Paralelos» (tarea 8.8, maqueta B; reglas-de-negocio.md, «Armar paralelos»):
 * cuántos completos lleva cada carrera que cuenta (`full`) y cuántos de sus esperados van a cada mixto (`mixed`, una columna
 * por mixto). Es la vista previa sin escribir: da los grupos que resultan, qué grupo que ya existe se queda en cada uno y
 * cuáles se cancelan, con ParallelPlacement como ArmParallelGroups, y el arreglo que se le manda. Pura: no lee la base.
 *
 * - Los grupos van como los arma ParallelGroups::layout(): primero los completos de cada carrera, en el orden de las
 *   carreras, con el tamaño objetivo de esperados; luego los mixtos, en el orden de sus columnas, sin las vacías. En cada
 *   mixto, la carrera con más esperados primero (la dueña de uno nuevo, como en ArmParallelGroups).
 * - Cupos (confirmado el 18 de noviembre de 2026): en un mixto, cada carrera recibe sus esperados en exclusiva y lo demás
 *   queda libre; en un grupo de una sola carrera, su cupo es toda la capacidad (supuesto por confirmar, como ParallelSlot).
 *   La capacidad es la del grupo que se queda o la de uno nuevo (`ParallelSubject::$newCapacity`).
 * - `left`: por carrera, sus esperados que no quedan en ningún grupo (negativo si se le dieron de más).
 *
 * Lo que llega del cliente se limpia: solo las carreras que cuentan, enteros de 0 a 999 (QuotaRules::MAX_SEATS) y hasta
 * ArmParallelGroups::MAX_GROUPS completos por carrera y columnas.
 */
final readonly class ParallelDraft
{
    /**
     * @param  array<string, int>  $full  completos por carrera que cuenta, en su orden
     * @param  list<array<string, int>>  $mixed  por columna, esperados por carrera (sin ceros; la de más primero)
     * @param  list<ParallelSlot>  $slots  los grupos que resultan
     * @param  array<int, int>  $columns  por índice de un grupo mixto, su columna
     * @param  list<ParallelExisting>  $cancelled  los grupos que ya existen y se cancelarían
     * @param  list<ParallelExisting>  $blocked  los que se cancelarían con docente: no se arma así
     * @param  array<string, int>  $left  por carrera, esperados sin lugar (negativo: de más)
     */
    private function __construct(
        public ParallelSubject $row,
        public array $full,
        public array $mixed,
        public array $slots,
        public array $columns,
        public array $cancelled,
        public array $blocked,
        public array $left,
    ) {}

    /**
     * El armado que sugiere Nexus: el de la sugerencia de la materia (ParallelSubject::$suggestion).
     */
    public static function suggested(ParallelSubject $row): self
    {
        $full = [];
        $mixed = [];

        foreach ($row->suggestion as $slot) {
            if ($slot->full) {
                $owner = (string) array_key_first($slot->expected);
                $full[$owner] = ($full[$owner] ?? 0) + 1;
            } else {
                $mixed[] = $slot->expected;
            }
        }

        return self::of($row, $full, $mixed);
    }

    /**
     * @param  array<array-key, mixed>  $full  completos por carrera, como llegan del cliente
     * @param  array<array-key, mixed>  $mixed  por columna, esperados por carrera, como llegan del cliente
     */
    public static function of(ParallelSubject $row, array $full, array $mixed): self
    {
        $careers = array_map(fn (ParallelCareer $career): string => $career->career->getKey(), $row->careers);
        $order = array_flip($careers);
        $clean = fn (mixed $value, int $max): int => is_numeric($value) ? max(0, min($max, (int) $value)) : 0;

        $fullCounts = [];

        foreach ($careers as $id) {
            $fullCounts[$id] = $clean($full[$id] ?? 0, ArmParallelGroups::MAX_GROUPS);
        }

        $columns = [];

        foreach (array_slice(array_values($mixed), 0, ArmParallelGroups::MAX_GROUPS) as $column) {
            $shares = [];

            foreach (is_array($column) ? $column : [] as $id => $value) {
                if (isset($order[$id]) && $clean($value, QuotaRules::MAX_SEATS) > 0) {
                    $shares[$id] = $clean($value, QuotaRules::MAX_SEATS);
                }
            }

            uksort($shares, fn (string $a, string $b): int => [$shares[$b], $order[$a]] <=> [$shares[$a], $order[$b]]);
            $columns[] = $shares;
        }

        $expected = [];
        $slotColumns = [];

        foreach ($fullCounts as $id => $count) {
            for ($i = 0; $i < $count; $i++) {
                $expected[] = [$id => $row->size()];
            }
        }

        $fullSlots = count($expected);

        foreach ($columns as $index => $shares) {
            if ($shares !== []) {
                $slotColumns[count($expected)] = $index;
                $expected[] = $shares;
            }
        }

        [$placement, $quotas] = self::place($row, $expected);
        $byId = [];

        foreach ($row->groups as $group) {
            $byId[$group->id] = $group;
        }

        $slots = [];

        foreach ($expected as $index => $shares) {
            $keeps = isset($placement->kept[$index]) ? $byId[$placement->kept[$index]] : null;
            $slots[] = new ParallelSlot($shares, $quotas[$index], $keeps?->ownerCareerId ?? (string) array_key_first($shares), $keeps?->capacity ?? $row->newCapacity, $index < $fullSlots, $keeps);
        }

        $left = [];

        foreach ($row->careers as $career) {
            $id = $career->career->getKey();
            $left[$id] = $career->expected - $fullCounts[$id] * $row->size() - array_sum(array_column($columns, $id));
        }

        return new self(
            $row,
            $fullCounts,
            $columns,
            $slots,
            $slotColumns,
            array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->cancelled),
            array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->blocked),
            $left,
        );
    }

    /**
     * Coloca los grupos que ya existen sobre el arreglo que se mandaría, como ArmParallelGroups. El cupo de un grupo de una
     * sola carrera depende de qué grupo se queda en él, y qué grupo se queda, del arreglo: se coloca hasta que el arreglo ya
     * no cambia (dos vueltas en la práctica).
     *
     * @param  list<array<string, int>>  $expected
     * @return array{0: ParallelPlacement, 1: list<array<string, int>>}
     */
    private static function place(ParallelSubject $row, array $expected): array
    {
        $groups = array_map(fn (ParallelExisting $group): array => $group->forPlacement(), $row->groups);
        $capacities = [];

        foreach ($row->groups as $group) {
            $capacities[$group->id] = $group->capacity;
        }

        $quotas = fn (array $kept): array => array_map(
            fn (array $shares, int $index): array => count($shares) > 1 ? $shares : [(string) array_key_first($shares) => isset($kept[$index]) ? $capacities[$kept[$index]] : $row->newCapacity],
            $expected,
            array_keys($expected),
        );

        $arrangement = $quotas([]);
        $placement = ParallelPlacement::place($arrangement, $groups);

        for ($round = 0; $round < 3; $round++) {
            $next = $quotas($placement->kept);

            if ($next === $arrangement) {
                break;
            }

            $arrangement = $next;
            $placement = ParallelPlacement::place($arrangement, $groups);
        }

        return [$placement, $arrangement];
    }

    /**
     * Lo que se le manda a ArmParallelGroups: por grupo, `carrera => lugares`.
     *
     * @return list<array<string, int>>
     */
    public function arrangement(): array
    {
        return array_map(fn (ParallelSlot $slot): array => $slot->quotas, $this->slots);
    }

    public function size(): int
    {
        return count($this->slots);
    }

    /**
     * Los grupos de menos contra lo pedido, con este armado.
     */
    public function saved(): int
    {
        return $this->row->requested() - $this->size();
    }

    /**
     * El nombre de un grupo que resulta: «Completo de ISIC» o «Mixto 2» (por su columna).
     */
    public function slotName(int $index): string
    {
        return isset($this->columns[$index])
            ? 'Mixto '.($this->columns[$index] + 1)
            : 'Completo de '.$this->code($this->slots[$index]->ownerCareerId);
    }

    /**
     * Los índices de los grupos cuyos cupos pasan su capacidad (HC-07).
     *
     * @return list<int>
     */
    public function overs(): array
    {
        return array_keys(array_filter($this->slots, fn (ParallelSlot $slot): bool => $slot->over() > 0));
    }

    /**
     * Las carreras del arreglo con su parte todavía pendiente.
     *
     * @return list<string>
     */
    public function pending(): array
    {
        $inArrangement = array_merge(...array_map(array_keys(...), $this->arrangement()));

        return array_values(array_map(
            fn (ParallelCareer $career): string => $career->career->code,
            array_filter($this->row->careers, fn (ParallelCareer $career): bool => ! $career->isAccepted() && in_array($career->career->getKey(), $inArrangement, true)),
        ));
    }

    /**
     * Por qué este armado no se puede armar, o null. El rol y la carga los dice la pantalla.
     */
    public function blockedReason(): ?string
    {
        if ($this->slots === []) {
            return 'Pon alumnos en al menos un grupo para armar.';
        }

        if ($this->size() > ArmParallelGroups::MAX_GROUPS) {
            return 'El arreglo lleva de 1 a '.ArmParallelGroups::MAX_GROUPS.' grupos.';
        }

        if ($this->pending() !== []) {
            return 'Falta aceptar la parte de '.self::list($this->pending()).': sus lugares nacen al aceptarla.';
        }

        if ($this->blocked !== []) {
            $group = $this->blocked[0];
            $owner = $this->code($group->ownerCareerId);

            return "El grupo {$group->code} de {$owner} tiene docente y el arreglo no le deja lugar: deja un grupo con lugares de {$owner} o quítale primero el docente.";
        }

        $over = $this->overs()[0] ?? null;

        if ($over !== null) {
            $lugares = $this->slots[$over]->over() === 1 ? 'lugar' : 'lugares';

            return "{$this->slotName($over)} pasa su capacidad por {$this->slots[$over]->over()} {$lugares} (HC-07): quita esperados de ese grupo.";
        }

        return null;
    }

    /**
     * El aviso que no impide armar: los esperados que se quedan sin grupo.
     */
    public function warning(): ?string
    {
        $short = array_filter($this->left, fn (int $left): bool => $left > 0);

        if ($short === []) {
            return null;
        }

        $parts = array_map(fn (int $left, string $id): string => "{$left} de {$this->code($id)}", $short, array_keys($short));

        return self::list($parts).' sin lugar: puedes armar así, pero esos alumnos no tendrán grupo.';
    }

    /**
     * Si es el armado que sugiere Nexus (sin contar las columnas vacías).
     */
    public function isSuggestion(): bool
    {
        $suggested = self::suggested($this->row);
        $nonEmpty = fn (array $columns): array => array_values(array_filter($columns));

        return $this->full == $suggested->full && $nonEmpty($this->mixed) == $nonEmpty($suggested->mixed);
    }

    /**
     * La clave de una carrera de la materia (las que cuentan o las regresadas), o la del grupo que ya existe.
     */
    public function code(string $careerId): string
    {
        foreach ([...$this->row->careers, ...$this->row->excluded] as $career) {
            if ($career->career->getKey() === $careerId) {
                return $career->career->code;
            }
        }

        return 'otra carrera';
    }

    /**
     * @param  list<string>  $items
     */
    private static function list(array $items): string
    {
        $last = array_pop($items);

        return $items === [] ? (string) $last : implode(', ', $items)." y {$last}";
    }
}
