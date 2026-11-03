<?php

use App\Modules\Core\Models\User;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `role_scopes` pasa a ser `role_assignments`, la única fuente de quién tiene qué puesto (ADR-0018, spec 6.3 y 6.4).
 *
 * Esquema:
 * - La tabla, sus índices y sus restricciones se renombran; `department_id` pasa a `org_unit_id`, con FK a
 *   `org_units` en lugar de la que agregó Academic a `departments`. Los valores sirven tal cual: cada departamento es
 *   una unidad con su mismo id.
 * - `role_assignments_one_jefe` y `role_assignments_one_subdireccion` (AZ-S7, supuesto por confirmar): un solo
 *   `jefe_departamento` y una sola `subdireccion` por unidad en cada fecha. La tabla no tiene borrado lógico, así que
 *   cuentan todas las filas, también las vencidas.
 * - `users.authz_version` (0) lo sube el trigger `role_assignments_touch_user` en cada alta, cambio o baja de un
 *   nombramiento, venga de donde venga; si el nombramiento cambia de usuario, sube la de los dos. Es parte de la llave
 *   de caché de la autorización (spec 9). `users.last_role` guarda el último perfil usado (AZ-S4).
 *
 * Datos que ya existen, con hoy en Ciudad Juárez:
 * - Los nombramientos de Subdirección pasan a la unidad Subdirección Académica (AZ-S3).
 * - Puestos exclusivos que chocan (supuesto por confirmar, como la migración 2026_10_30_000101 de jefaturas): por
 *   unidad van primero los vigentes o por empezar y después los ya terminados, y en cada grupo por `valid_from`,
 *   `created_at` e `id`. Se conserva cada uno que no se traslapa con uno ya conservado; los demás se **eliminan**,
 *   porque terminarlos ayer no basta (la exclusión cuenta también los vencidos). Cada eliminado deja una entrada en la
 *   bitácora sobre su usuario, sin causante, con lo que tenía y el nombramiento que se quedó.
 * - Cada usuario con el rol `admin` de spatie recibe un nombramiento `admin` sin alcance desde hoy.
 *
 * down() regresa el esquema y los datos que puede: quita los nombramientos `admin` y deja la Subdirección sin
 * unidad; los eliminados no vuelven. Falla si algún nombramiento quedó en una unidad que no es un departamento.
 */
return new class extends Migration
{
    /** @var array<string, string> puestos exclusivos y su nombre en la bitácora */
    private const array EXCLUSIVE = [
        'jefe_departamento' => 'Jefe de departamento académico',
        'subdireccion' => 'Subdirección Académica',
    ];

    public function up(): void
    {
        DB::statement('create extension if not exists btree_gist');

        DB::unprepared(<<<'SQL'
            alter table role_scopes rename to role_assignments;
            alter table role_assignments rename column department_id to org_unit_id;
            alter table role_assignments drop constraint if exists role_scopes_department_id_foreign;
            alter index role_scopes_pkey rename to role_assignments_pkey;
            alter index role_scopes_user_id_role_index rename to role_assignments_user_id_role_index;
            alter index role_scopes_campus_id_index rename to role_assignments_campus_id_index;
            alter index role_scopes_department_id_index rename to role_assignments_org_unit_id_index;
            alter index role_scopes_career_id_index rename to role_assignments_career_id_index;
            alter table role_assignments rename constraint role_scopes_validity_check to role_assignments_validity_check;
            alter table role_assignments rename constraint role_scopes_user_id_foreign to role_assignments_user_id_foreign;
            alter table role_assignments rename constraint role_scopes_campus_id_foreign to role_assignments_campus_id_foreign;
            alter table role_assignments rename constraint role_scopes_career_id_foreign to role_assignments_career_id_foreign;
            alter table role_assignments add constraint role_assignments_org_unit_id_foreign
                foreign key (org_unit_id) references org_units (id);

            alter table users add column authz_version integer not null default 0;
            alter table users add column last_role varchar(255) null;
            SQL);

        $this->moveSubdirectionsToAcademicUnit();
        $this->removeClashingExclusives(today()->toDateString());
        $this->appointAdmins(today()->toDateString());

        DB::unprepared(<<<'SQL'
            alter table role_assignments add constraint role_assignments_one_jefe exclude using gist (
                org_unit_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (role = 'jefe_departamento');

            alter table role_assignments add constraint role_assignments_one_subdireccion exclude using gist (
                org_unit_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (role = 'subdireccion');

            create or replace function role_assignments_touch_user() returns trigger
            language plpgsql as $$
            begin
                update users set authz_version = authz_version + 1 where id = coalesce(new.user_id, old.user_id);

                if tg_op = 'UPDATE' and old.user_id is distinct from new.user_id then
                    update users set authz_version = authz_version + 1 where id = old.user_id;
                end if;

                return null;
            end;
            $$;

            create trigger role_assignments_touch_user
                after insert or update or delete on role_assignments
                for each row execute function role_assignments_touch_user();
            SQL);
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            drop trigger if exists role_assignments_touch_user on role_assignments;
            drop function if exists role_assignments_touch_user();
            alter table role_assignments drop constraint if exists role_assignments_one_jefe;
            alter table role_assignments drop constraint if exists role_assignments_one_subdireccion;
            delete from role_assignments where role = 'admin';
            update role_assignments set org_unit_id = null where role = 'subdireccion';
            alter table role_assignments drop constraint role_assignments_org_unit_id_foreign;
            alter table users drop column last_role;
            alter table users drop column authz_version;
            alter table role_assignments rename constraint role_assignments_career_id_foreign to role_scopes_career_id_foreign;
            alter table role_assignments rename constraint role_assignments_campus_id_foreign to role_scopes_campus_id_foreign;
            alter table role_assignments rename constraint role_assignments_user_id_foreign to role_scopes_user_id_foreign;
            alter table role_assignments rename constraint role_assignments_validity_check to role_scopes_validity_check;
            alter index role_assignments_career_id_index rename to role_scopes_career_id_index;
            alter index role_assignments_org_unit_id_index rename to role_scopes_department_id_index;
            alter index role_assignments_campus_id_index rename to role_scopes_campus_id_index;
            alter index role_assignments_user_id_role_index rename to role_scopes_user_id_role_index;
            alter index role_assignments_pkey rename to role_scopes_pkey;
            alter table role_assignments rename column org_unit_id to department_id;
            alter table role_assignments rename to role_scopes;
            SQL);

        if (Schema::hasTable('departments')) {
            DB::statement('alter table role_scopes add constraint role_scopes_department_id_foreign foreign key (department_id) references departments (id)');
        }
    }

    /**
     * Los nombramientos de Subdirección, que no tenían departamento, quedan sobre la unidad Subdirección Académica.
     *
     * @throws RuntimeException si hay alguno y falta esa unidad
     */
    private function moveSubdirectionsToAcademicUnit(): void
    {
        if (! DB::table('role_assignments')->where('role', 'subdireccion')->exists()) {
            return;
        }

        $academic = DB::table('org_units')
            ->where('code', 'SUB-ACADEMICA')
            ->where('kind', '<>', 'departamento_academico')
            ->whereNull('deleted_at')
            ->value('id')
            ?? throw new RuntimeException('Falta la unidad SUB-ACADEMICA para los nombramientos de Subdirección.');

        DB::table('role_assignments')->where('role', 'subdireccion')->update(['org_unit_id' => $academic, 'updated_at' => now()]);
    }

    /**
     * Un solo nombramiento por unidad en cada fecha de cada puesto exclusivo; los que chocan se eliminan con su
     * entrada en la bitácora.
     */
    private function removeClashingExclusives(string $today): void
    {
        $assignments = DB::table('role_assignments')
            ->whereIn('role', array_keys(self::EXCLUSIVE))
            ->whereNotNull('org_unit_id')
            ->get()
            ->groupBy(fn (object $row): string => "{$row->role}|{$row->org_unit_id}");

        foreach ($assignments as $rows) {
            $kept = [];

            foreach ($this->byPriority($rows, $today) as $row) {
                $keeper = $this->firstOverlapping($row, $kept);

                if ($keeper === null) {
                    $kept[] = $row;

                    continue;
                }

                $this->logRemoval($row, $keeper);
                DB::table('role_assignments')->where('id', $row->id)->delete();
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
     * El primero de `$kept` cuyo periodo (con los dos días incluidos) se traslapa con el de `$row`.
     *
     * @param  list<object>  $kept
     */
    private function firstOverlapping(object $row, array $kept): ?object
    {
        foreach ($kept as $other) {
            $startsBeforeOtherEnds = $other->valid_to === null || $row->valid_from <= $other->valid_to;
            $endsAfterOtherStarts = $row->valid_to === null || $row->valid_to >= $other->valid_from;

            if ($startsBeforeOtherEnds && $endsAfterOtherStarts) {
                return $other;
            }
        }

        return null;
    }

    private function logRemoval(object $row, object $keeper): void
    {
        $attributes = [
            'role' => $row->role,
            'campus_id' => $row->campus_id,
            'org_unit_id' => $row->org_unit_id,
            'career_id' => $row->career_id,
            'valid_from' => $row->valid_from,
            'valid_to' => $row->valid_to,
        ];
        $unit = DB::table('org_units')->where('id', $row->org_unit_id)->value('code');
        $now = now();

        DB::table('activity_log')->insert([
            'log_name' => config('activitylog.default_log_name', 'default'),
            'description' => 'Eliminó el nombramiento de '.self::EXCLUSIVE[$row->role]." en {$unit} porque se traslapaba con otro y el puesto es uno por unidad",
            'subject_type' => User::class,
            'subject_id' => $row->user_id,
            'event' => 'deleted',
            'attribute_changes' => json_encode(['old' => $attributes]),
            'properties' => json_encode([
                'role_assignment_id' => $row->id,
                'kept_role_assignment_id' => $keeper->id,
                ...$attributes,
            ]),
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    /**
     * Un nombramiento `admin` sin alcance, desde hoy, para cada usuario con el rol `admin` de spatie que no lo tenga.
     */
    private function appointAdmins(string $today): void
    {
        DB::insert(<<<'SQL'
            insert into role_assignments (user_id, role, valid_from, created_at, updated_at)
            select distinct users.id, 'admin', ?::date, now(), now()
            from model_has_roles
            join roles on roles.id = model_has_roles.role_id and roles.name = 'admin'
            join users on users.id = model_has_roles.model_id
            where model_has_roles.model_type = ?
              and not exists (
                select 1 from role_assignments existing where existing.user_id = users.id and existing.role = 'admin'
            )
            SQL, [$today, (new User)->getMorphClass()]);
    }
};
