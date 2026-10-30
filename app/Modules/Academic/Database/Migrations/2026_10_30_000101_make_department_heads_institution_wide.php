<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * El jefe de departamento es uno por departamento para todo el Tec y la Subdirección Académica es de todo el Tec
 * (confirmado el 30 de octubre de 2026). El jefe de proyecto docente sigue en un departamento y un campus (supuesto
 * por confirmar). Cómo se convierten los datos que ya existen, abajo, también es un supuesto por confirmar.
 *
 * Esquema de `department_heads`:
 * - `campus_id` acepta nulo, y el CHECK `department_heads_campus_check` lo exige nulo para `jefe` y lleno para
 *   `jefe_proyecto_docente`.
 * - `department_heads_one_jefe`: un solo `jefe` por departamento en cada fecha, sin campus.
 * - `department_heads_no_duplicate`: la misma persona no es dos veces jefe de proyecto docente del mismo
 *   departamento y campus con fechas que se traslapan. Del jefe ya se encarga la otra exclusión.
 * - Las dos exclusiones no cuentan los borrados (ADR-0016).
 *
 * Conversión de los datos que ya existen, con hoy en Ciudad Juárez:
 * - Jefes de cada departamento, en este orden:
 *   1. los vigentes o por empezar (`valid_to` nulo o desde hoy);
 *   2. después los que ya terminaron.
 *   Dentro de cada grupo van por `valid_from`, `created_at` e `id`.
 * - El primero se conserva: es el vigente más antiguo. Cada uno de los siguientes se conserva solo si no se traslapa
 *   con uno ya conservado.
 * - Todos quedan sin campus, porque el CHECK no deja de otra forma, y los ya borrados también.
 * - Un conservado pasa su alcance a `campus_id` nulo. Ese alcance es el que escribió AppointDepartmentHead: mismo
 *   usuario, rol, campus, departamento y `valid_from`, sin carrera.
 * - Uno que choca y seguía vigente o por empezar termina con la regla de CloseRoleScopes, junto con su alcance:
 *   - si ya empezó, los dos terminan ayer;
 *   - si empieza hoy o después, la jefatura se borra de forma lógica y el alcance de verdad.
 * - Todo el que choca queda borrado de forma lógica, porque su periodo se traslapa con el del jefe conservado aunque
 *   termine ayer. Su alcance, ya vencido, conserva su campus.
 * - Otros alcances de `jefe_departamento` que no salieron de una jefatura se quedan como están: siguen valiendo
 *   solo en su campus.
 *
 * Alcances de Subdirección de cada usuario:
 * - Van en el mismo orden: los que valen hoy o después primero, por `valid_from`, `created_at` e `id`.
 * - Quedan sin campus los que no se traslapan con uno anterior y se borran los demás. Así cada usuario tiene un solo
 *   alcance general en cada fecha.
 *
 * Es una corrección de esquema y no deja bitácora. El campus que tenían las filas `jefe`, y los alcances de
 * Subdirección que se borran por traslaparse, no quedan registrados en ningún lado. Hoy solo hay datos de desarrollo
 * y demo. Las jefaturas nombradas con AppointDepartmentHead conservan su campus en la bitácora de su nombramiento.
 * down() regresa el esquema solo si ningún jefe quedó sin campus, porque no hay de dónde sacarlo.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('alter table department_heads drop constraint department_heads_one_jefe');
        DB::statement('alter table department_heads drop constraint department_heads_no_duplicate');
        DB::statement('alter table department_heads alter column campus_id drop not null');

        $today = today()->toDateString();
        $yesterday = today()->subDay()->toDateString();

        $this->convertHeads($today, $yesterday);
        $this->convertSubdirections($today);

        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_campus_check check (
                (kind = 'jefe') = (campus_id is null)
            )
            SQL);
        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_one_jefe exclude using gist (
                department_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (kind = 'jefe' and deleted_at is null)
            SQL);
        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_no_duplicate exclude using gist (
                department_id with =,
                campus_id with =,
                user_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (kind = 'jefe_proyecto_docente' and deleted_at is null)
            SQL);
    }

    public function down(): void
    {
        if (DB::table('department_heads')->whereNull('campus_id')->exists()) {
            throw new RuntimeException('No se puede regresar: hay jefes de departamento sin campus y no se sabe de qué campus eran.');
        }

        DB::statement('alter table department_heads drop constraint department_heads_campus_check');
        DB::statement('alter table department_heads drop constraint department_heads_one_jefe');
        DB::statement('alter table department_heads drop constraint department_heads_no_duplicate');
        DB::statement('alter table department_heads alter column campus_id set not null');
        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_one_jefe exclude using gist (
                department_id with =,
                campus_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (kind = 'jefe' and deleted_at is null)
            SQL);
        DB::statement(<<<'SQL'
            alter table department_heads add constraint department_heads_no_duplicate exclude using gist (
                department_id with =,
                campus_id with =,
                user_id with =,
                kind with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (deleted_at is null)
            SQL);
    }

    /**
     * Un solo jefe en cada fecha por departamento, sin campus; los que chocan terminan y quedan borrados.
     */
    private function convertHeads(string $today, string $yesterday): void
    {
        $heads = DB::table('department_heads')
            ->where('kind', 'jefe')
            ->whereNull('deleted_at')
            ->get()
            ->groupBy('department_id');

        foreach ($heads as $rows) {
            $kept = [];

            foreach ($this->byPriority($rows, $today) as $head) {
                if (! $this->overlapsAny($head, $kept)) {
                    $kept[] = $head;
                    $this->scopeOf($head)->update(['campus_id' => null, 'updated_at' => now()]);

                    continue;
                }

                $this->endClashing($head, $today, $yesterday);
            }
        }

        DB::table('department_heads')->where('kind', 'jefe')->whereNotNull('campus_id')->update(['campus_id' => null]);
    }

    /**
     * Un jefe que choca con uno conservado: si seguía vigente o por empezar, termina ayer con su alcance (o se
     * borran si empiezan hoy o después); luego queda borrado de forma lógica.
     */
    private function endClashing(object $head, string $today, string $yesterday): void
    {
        $changes = ['deleted_at' => now(), 'updated_at' => now()];
        $current = $head->valid_to === null || $head->valid_to >= $today;

        if ($current) {
            $scope = $this->scopeOf($head)->where(fn ($query) => $query->whereNull('valid_to')->orWhere('valid_to', '>=', $today));

            if ($head->valid_from < $today) {
                $changes['valid_to'] = $yesterday;
                $scope->update(['valid_to' => $yesterday, 'updated_at' => now()]);
            } else {
                $scope->delete();
            }
        }

        DB::table('department_heads')->where('id', $head->id)->update($changes);
    }

    /**
     * Un alcance de Subdirección sin campus por usuario en cada fecha.
     */
    private function convertSubdirections(string $today): void
    {
        $scopes = DB::table('role_scopes')->where('role', 'subdireccion')->get()->groupBy('user_id');

        foreach ($scopes as $rows) {
            $kept = [];

            foreach ($this->byPriority($rows, $today) as $scope) {
                if ($this->overlapsAny($scope, $kept)) {
                    DB::table('role_scopes')->where('id', $scope->id)->delete();

                    continue;
                }

                $kept[] = $scope;

                if ($scope->campus_id !== null) {
                    DB::table('role_scopes')->where('id', $scope->id)->update(['campus_id' => null, 'updated_at' => now()]);
                }
            }
        }
    }

    /**
     * Primero los vigentes o por empezar y después los que ya terminaron; en cada grupo, por `valid_from`,
     * `created_at` e `id`.
     *
     * @param  Collection<int, object>  $rows
     * @return list<object>
     */
    private function byPriority(Collection $rows, string $today): array
    {
        return $rows->sortBy([
            fn (object $a, object $b): int => ($a->valid_to !== null && $a->valid_to < $today) <=> ($b->valid_to !== null && $b->valid_to < $today),
            fn (object $a, object $b): int => $a->valid_from <=> $b->valid_from,
            fn (object $a, object $b): int => (string) $a->created_at <=> (string) $b->created_at,
            fn (object $a, object $b): int => $a->id <=> $b->id,
        ])->values()->all();
    }

    /**
     * Si el periodo de `$row` (con los dos días incluidos) se traslapa con el de alguno de `$kept`.
     *
     * @param  list<object>  $kept
     */
    private function overlapsAny(object $row, array $kept): bool
    {
        foreach ($kept as $other) {
            $startsBeforeOtherEnds = $other->valid_to === null || $row->valid_from <= $other->valid_to;
            $endsAfterOtherStarts = $row->valid_to === null || $row->valid_to >= $other->valid_from;

            if ($startsBeforeOtherEnds && $endsAfterOtherStarts) {
                return true;
            }
        }

        return false;
    }

    /**
     * El alcance que escribió AppointDepartmentHead para la jefatura, con el campus que tenía.
     */
    private function scopeOf(object $head): Builder
    {
        return DB::table('role_scopes')
            ->where('user_id', $head->user_id)
            ->where('role', 'jefe_departamento')
            ->where('campus_id', $head->campus_id)
            ->where('department_id', $head->department_id)
            ->whereNull('career_id')
            ->where('valid_from', $head->valid_from);
    }
};
