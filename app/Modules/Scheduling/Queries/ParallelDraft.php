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
 *   carreras, con el tamaño de un completo de esperados (ParallelSubject::size(): el objetivo sin pasar la capacidad de
 *   un grupo nuevo); luego los mixtos, en el orden de sus columnas, sin las vacías. En cada
 *   mixto, la carrera con más esperados primero (la dueña de uno nuevo, como en ArmParallelGroups).
 * - Cupos (confirmado el 17 de noviembre de 2026): en un mixto, cada carrera recibe sus esperados en exclusiva y lo demás
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
     * @param  list<ParallelExisting>  $blocked  los que se cancelarían sin poder cancelarse (con docente o con una solicitud de
     *                                           cambio abierta): no se arma así
     * @param  array<string, int>  $left  por carrera, esperados de menos (negativo: de más)
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
        [$full, $mixed] = self::suggestionInputs($row);

        return self::of($row, $full, $mixed);
    }

    /**
     * Los completos por carrera y los mixtos de la sugerencia, como los editaría el jefe.
     *
     * @return array{0: array<string, int>, 1: list<array<string, int>>}
     */
    private static function suggestionInputs(ParallelSubject $row): array
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

        return [$full, $mixed];
    }

    /**
     * El armado que ya existe, para una materia armada: cada grupo suelto con cupos de una sola carrera de las que cuentan es
     * un completo de ella, y cada uno con cupos de varias es un mixto con sus cupos como esperados (supuesto por confirmar:
     * el grupo no guarda sus esperados, y en un mixto los cupos son los esperados en exclusiva).
     */
    public static function current(ParallelSubject $row): self
    {
        $counting = array_flip(array_map(fn (ParallelCareer $career): string => $career->career->getKey(), $row->careers));
        $full = [];
        $mixed = [];

        foreach ($row->groups as $group) {
            $quotas = array_intersect_key($group->quotas, $counting);

            if (count($quotas) > 1) {
                $mixed[] = $quotas;
            } elseif (count($quotas) === 1) {
                $full[(string) array_key_first($quotas)] = ($full[(string) array_key_first($quotas)] ?? 0) + 1;
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

        [$suggestedFull, $suggestedMixed] = self::suggestionInputs($row);

        if (array_filter($fullCounts) == $suggestedFull && array_values(array_filter($columns)) == $suggestedMixed) {
            return self::fromSuggestion($row, $fullCounts, $columns);
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

        return new self(
            $row,
            $fullCounts,
            $columns,
            $slots,
            $slotColumns,
            array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->cancelled),
            array_map(fn (string $id): ParallelExisting => $byId[$id], $placement->blocked),
            self::left($row, $slots),
        );
    }

    /**
     * Lo que sugiere Nexus tal cual lo arma ParallelsQuery (ya armable: ver ParallelsQuery::armable()), con las columnas del
     * jefe, que pueden traer mixtos vacíos entre medio.
     *
     * @param  array<string, int>  $full
     * @param  list<array<string, int>>  $columns
     */
    private static function fromSuggestion(ParallelSubject $row, array $full, array $columns): self
    {
        $slotColumns = [];
        $filled = array_keys(array_filter($columns));

        foreach ($row->suggestion as $index => $slot) {
            if (! $slot->full) {
                $slotColumns[$index] = array_shift($filled);
            }
        }

        return new self($row, $full, $columns, $row->suggestion, $slotColumns, $row->cancelled, $row->blocked(), self::left($row, $row->suggestion));
    }

    /**
     * Por carrera, sus esperados que no quedan en ningún grupo: los de cada grupo, hasta su cupo (un completo no recibe más
     * que su cupo aunque el tamaño de un completo sea mayor). Negativo si se le dieron de más.
     *
     * @param  list<ParallelSlot>  $slots
     * @return array<string, int>
     */
    private static function left(ParallelSubject $row, array $slots): array
    {
        $left = [];

        foreach ($row->careers as $career) {
            $id = $career->career->getKey();
            $left[$id] = $career->expected - array_sum(array_map(fn (ParallelSlot $slot): int => min($slot->expected[$id] ?? 0, $slot->quotas[$id] ?? 0), $slots));
        }

        return $left;
    }

    /**
     * Coloca los grupos que ya existen sobre el arreglo que se mandaría, como ArmParallelGroups, y devuelve los dos juntos:
     * lo que se ve es lo que hace la Action con ese mismo arreglo. El cupo de un grupo de una sola carrera es la capacidad
     * del grupo que se queda en él, y qué grupo se queda depende del arreglo: primero se coloca con la capacidad de uno nuevo
     * y se ponen las capacidades de los que se quedaron; si al colocar otra vez sobre ese arreglo se queda lo mismo, ese es.
     * Si no (dos grupos de la misma carrera con capacidades distintas pueden ir y venir entre sus completos), los grupos de una
     * sola carrera de cada carrera llevan el mismo cupo, el más chico que cabe en cualquiera de sus grupos (la capacidad de
     * uno nuevo o la de los suyos que ya existen): así la colocación no depende del cupo y ninguno pasa su capacidad (HC-07;
     * supuesto por confirmar: se pierden lugares de los grupos más grandes antes que proponer un arreglo que no se arma).
     *
     * @param  list<array<string, int>>  $expected
     * @return array{0: ParallelPlacement, 1: list<array<string, int>>}
     */
    private static function place(ParallelSubject $row, array $expected): array
    {
        $groups = array_map(fn (ParallelExisting $group): array => $group->forPlacement(), $row->groups);
        $capacities = [];
        $smallest = [];

        foreach ($row->groups as $group) {
            $capacities[$group->id] = $group->capacity;
            $smallest[$group->ownerCareerId] = min($smallest[$group->ownerCareerId] ?? $row->newCapacity, $group->capacity);
        }

        $quotas = fn (callable $seats): array => array_map(
            fn (array $shares, int $index): array => count($shares) > 1 ? $shares : [(string) array_key_first($shares) => $seats($index, (string) array_key_first($shares))],
            $expected,
            array_keys($expected),
        );

        $first = ParallelPlacement::place($quotas(fn (): int => $row->newCapacity), $groups);
        $arrangement = $quotas(fn (int $index): int => isset($first->kept[$index]) ? $capacities[$first->kept[$index]] : $row->newCapacity);
        $placement = ParallelPlacement::place($arrangement, $groups);

        if ($placement->kept === $first->kept) {
            return [$placement, $arrangement];
        }

        $arrangement = $quotas(fn (int $index, string $career): int => $smallest[$career] ?? $row->newCapacity);

        return [ParallelPlacement::place($arrangement, $groups), $arrangement];
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
     * Los esperados de una carrera que llevan sus completos: los de cada uno hasta su cupo, que es la capacidad del grupo
     * (E-08 del ensayo 8.11: no los completos por el tamaño objetivo).
     */
    public function expectedInFull(string $careerId): int
    {
        return array_sum(array_map(
            fn (ParallelSlot $slot): int => $slot->full ? min($slot->expected[$careerId] ?? 0, $slot->quotas[$careerId] ?? 0) : 0,
            $this->slots,
        ));
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
            return 'Falta aceptar la parte de '.collect($this->pending())->join(', ', ' y ').': sus lugares nacen al aceptarla.';
        }

        if ($this->blocked !== []) {
            return ParallelExisting::lockReasonOf($this->blocked, $this->code(...));
        }

        $over = $this->overs()[0] ?? null;

        if ($over !== null) {
            $lugares = $this->slots[$over]->over() === 1 ? 'lugar' : 'lugares';

            return "{$this->slotName($over)} pasa su capacidad por {$this->slots[$over]->over()} {$lugares} (HC-07): quita esperados de ese grupo.";
        }

        return null;
    }

    /**
     * Los grupos con docente que no se pueden cancelar, cuando son lo que dice blockedReason() (antes no falta aceptar una
     * parte ni el armado está vacío o pasa de los grupos que se permiten): a quiénes quitarles el docente en Asignación
     * docente (G-05 del recorrido final del ensayo 8.11). Vacío si no.
     *
     * @return list<ParallelExisting>
     */
    public function teacherBlocked(): array
    {
        if ($this->slots === [] || $this->size() > ArmParallelGroups::MAX_GROUPS || $this->pending() !== []) {
            return [];
        }

        return array_values(array_filter($this->blocked, fn (ParallelExisting $group): bool => $group->hasTeacher()));
    }

    /**
     * El aviso que no impide armar: los esperados que se quedan sin grupo («de menos», como `ParallelSubject::$short`).
     */
    public function warning(): ?string
    {
        $short = array_filter($this->left, fn (int $left): bool => $left > 0);

        if ($short === []) {
            return null;
        }

        $parts = array_map(fn (int $left, string $id): string => "{$left} de {$this->code($id)}", $short, array_keys($short));

        return 'Quedan de menos '.collect($parts)->join(', ', ' y ').': no caben en ningún grupo. Puedes armar así, pero esos alumnos no tendrán grupo.';
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
}
