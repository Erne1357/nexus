<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.P8: las jefaturas viven solo en `role_assignments` y `department_heads` queda como registro histórico de solo
 * lectura (AZ-S14, confirmado el 3 de noviembre de 2026). Se conserva porque en Academic nada se borra físicamente y
 * la bitácora cita sus ids (`department_head_id`).
 *
 * up(), en la transacción de la migración:
 * 1. Revisión previa: si hay dos `jefe_proyecto_docente` del mismo usuario, unidad y campus con fechas que se
 *    traslapan, se detiene con un RuntimeException que los nombra y no escribe nada.
 * 2. Conciliación (AZ-S14). Por cada jefatura sin borrar busca su nombramiento gemelo: mismo `user_id`, puesto (`jefe`
 *    es `jefe_departamento`), `org_unit_id` = `department_id`, el mismo campus (o ninguno), sin carrera y el mismo
 *    `valid_from`. Manda la fecha de fin más temprana y nunca se crea ni se alarga un nombramiento:
 *    - si la jefatura termina antes (o el gemelo no tiene fin), el gemelo termina ese día, con una entrada `updated`;
 *    - si el gemelo termina antes, se queda como está, con una entrada `skipped` que dice la diferencia;
 *    - con las dos fechas iguales no deja nada;
 *    - sin gemelo, la jefatura no pasa y deja una entrada `skipped`.
 *    Las entradas van sobre el usuario, sin causante. Las jefaturas borradas no se tocan.
 * 3. `role_assignments_jpd_no_duplicate`: la misma persona no es dos veces jefe de proyecto docente del mismo
 *    departamento y campus con fechas que se traslapan (confirmado el 29 de octubre de 2026; antes lo cuidaba
 *    `department_heads_no_duplicate`).
 * 4. Los triggers `department_heads_read_only_rows` y `department_heads_read_only_truncate` rechazan cualquier alta,
 *    cambio, baja o vaciado de `department_heads`, también el TRUNCATE en cascada desde `departments`.
 *
 * Es idempotente: correrla otra vez no cambia ningún nombramiento ni repite una entrada de la bitácora.
 *
 * down() quita los triggers, la función y la exclusión. Lo acortado no vuelve, pero queda en la bitácora.
 *
 * `create or replace` porque `migrate:fresh` borra las tablas (y con ellas los triggers) pero no las funciones.
 */
return new class extends Migration
{
    /** El `subject_type` de las entradas sobre un usuario: la migración usa solo DB y SQL, sin modelos. */
    private const string USER_MORPH = 'App\\Modules\\Core\\Models\\User';

    /** @var array<string, array{role: string, label: string}> el puesto de cada `kind` y su nombre en la bitácora */
    private const array KINDS = [
        'jefe' => ['role' => 'jefe_departamento', 'label' => 'Jefe de departamento académico'],
        'jefe_proyecto_docente' => ['role' => 'jefe_proyecto_docente', 'label' => 'Jefe de proyecto docente'],
    ];

    public function up(): void
    {
        $this->ensureNoDuplicatedProjectLeads();

        foreach ($this->headships() as $head) {
            $this->reconcile($head);
        }

        DB::unprepared(<<<'SQL'
            alter table role_assignments drop constraint if exists role_assignments_jpd_no_duplicate;

            alter table role_assignments add constraint role_assignments_jpd_no_duplicate exclude using gist (
                user_id with =,
                org_unit_id with =,
                campus_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (role = 'jefe_proyecto_docente');

            create or replace function department_heads_reject_changes() returns trigger
            language plpgsql as $$
            begin
                raise exception 'department_heads es histórica desde 3.P8 (las jefaturas viven en role_assignments): no se permite %', tg_op;
            end;
            $$;

            drop trigger if exists department_heads_read_only_rows on department_heads;
            drop trigger if exists department_heads_read_only_truncate on department_heads;

            create trigger department_heads_read_only_rows
                before insert or update or delete on department_heads
                for each row execute function department_heads_reject_changes();

            create trigger department_heads_read_only_truncate
                before truncate on department_heads
                for each statement execute function department_heads_reject_changes();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists department_heads_read_only_truncate on department_heads;
            drop trigger if exists department_heads_read_only_rows on department_heads;
            drop function if exists department_heads_reject_changes();
            alter table role_assignments drop constraint if exists role_assignments_jpd_no_duplicate;
            SQL);
    }

    /**
     * La exclusión nueva no se puede agregar si ya hay jefes de proyecto docente repetidos que se traslapan: se
     * detiene antes de escribir nada y nombra cada par.
     *
     * @throws RuntimeException
     */
    private function ensureNoDuplicatedProjectLeads(): void
    {
        $pairs = DB::select(<<<'SQL'
            select users.name as person, org_units.name as unit, campuses.code as campus
            from role_assignments a
            join role_assignments b
                on b.id > a.id
                and b.role = a.role
                and b.user_id = a.user_id
                and b.org_unit_id = a.org_unit_id
                and b.campus_id = a.campus_id
                and daterange(b.valid_from, b.valid_to, '[]') && daterange(a.valid_from, a.valid_to, '[]')
            join users on users.id = a.user_id
            join org_units on org_units.id = a.org_unit_id
            join campuses on campuses.id = a.campus_id
            where a.role = 'jefe_proyecto_docente'
            order by users.name, org_units.name, campuses.code, a.id, b.id
            SQL);

        if ($pairs === []) {
            return;
        }

        $list = implode('; ', array_map(fn (object $pair): string => "{$pair->person} en {$pair->unit} ({$pair->campus})", $pairs));

        throw new RuntimeException("Hay jefes de proyecto docente repetidos que se traslapan: {$list}. Termina uno de cada par antes de migrar.");
    }

    /**
     * Las jefaturas sin borrar, con el nombre de su usuario, de su departamento y el código de su campus (aunque
     * estén borrados).
     *
     * @return list<object>
     */
    private function headships(): array
    {
        return DB::select(<<<'SQL'
            select department_heads.*, users.name as person, departments.name as department, campuses.code as campus
            from department_heads
            join users on users.id = department_heads.user_id
            join departments on departments.id = department_heads.department_id
            left join campuses on campuses.id = department_heads.campus_id
            where department_heads.deleted_at is null
            order by department_heads.valid_from, department_heads.id
            SQL);
    }

    private function reconcile(object $head): void
    {
        $twin = DB::table('role_assignments')
            ->where('user_id', $head->user_id)
            ->where('role', self::KINDS[$head->kind]['role'])
            ->where('org_unit_id', $head->department_id)
            ->whereRaw('campus_id is not distinct from ?::uuid', [$head->campus_id])
            ->whereNull('career_id')
            ->where('valid_from', $head->valid_from)
            ->orderBy('id')
            ->first();

        $position = self::KINDS[$head->kind]['label']." de {$head->department}".($head->campus === null ? '' : " en {$head->campus}");

        if ($twin === null) {
            $period = 'del '.$this->day($head->valid_from).($head->valid_to === null ? '' : ' al '.$this->day($head->valid_to));

            $this->log($head->user_id, 'skipped', "La jefatura de {$head->person} como {$position}, {$period}, no tenía nombramiento: no pasó a role_assignments (3.P8)", [
                'department_head_id' => $head->id,
            ]);

            return;
        }

        $properties = ['role_assignment_id' => $twin->id, 'department_head_id' => $head->id];

        if ($head->valid_to !== null && ($twin->valid_to === null || $head->valid_to < $twin->valid_to)) {
            DB::table('role_assignments')->where('id', $twin->id)->update(['valid_to' => $head->valid_to, 'updated_at' => now()]);

            $this->log($head->user_id, 'updated', "Terminó el nombramiento de {$head->person} como {$position} el ".$this->day($head->valid_to).', el día en que terminó su jefatura (3.P8)', $properties, [
                'attributes' => ['valid_to' => $head->valid_to],
                'old' => ['valid_to' => $twin->valid_to],
            ]);
        } elseif ($twin->valid_to !== null && ($head->valid_to === null || $twin->valid_to < $head->valid_to)) {
            $this->log($head->user_id, 'skipped', "La jefatura de {$head->person} como {$position} termina después que su nombramiento (".$this->day($twin->valid_to).'): se quedó la fecha más temprana (3.P8)', $properties);
        }
    }

    /**
     * Una entrada de la bitácora sobre el usuario, sin causante; si la misma ya está (otra corrida), no la repite.
     *
     * @param  array<string, string>  $properties
     * @param  array{attributes: array<string, ?string>, old: array<string, ?string>}|null  $changes
     */
    private function log(string $userId, string $event, string $description, array $properties, ?array $changes = null): void
    {
        $logged = DB::table('activity_log')
            ->where('subject_type', self::USER_MORPH)
            ->where('subject_id', $userId)
            ->where('event', $event)
            ->where('description', $description)
            ->where('properties->department_head_id', $properties['department_head_id'])
            ->exists();

        if ($logged) {
            return;
        }

        DB::table('activity_log')->insert([
            'log_name' => config('activitylog.default_log_name', 'default'),
            'description' => $description,
            'subject_type' => self::USER_MORPH,
            'subject_id' => $userId,
            'event' => $event,
            'attribute_changes' => $changes === null ? null : json_encode($changes),
            'properties' => json_encode($properties),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function day(string $date): string
    {
        return CarbonImmutable::parse($date)->format('d/m/Y');
    }
};
