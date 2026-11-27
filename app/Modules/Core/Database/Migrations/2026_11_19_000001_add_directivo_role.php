<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El puesto `directivo` (AZ-03, R-V2-4, confirmado el 19 de noviembre de 2026): el Director y las subdirecciones que no
 * son la Académica leen todo lo académico del Tec. Se nombra sobre su unidad (la Dirección o una subdirección), sin
 * campus ni modalidad, y tiene un solo titular vigente por unidad, como el jefe y la Subdirección (AZ-S7).
 *
 * up(), en una transacción:
 * 1. Reemplaza el CHECK `role_assignments_scope_complete` con el caso `directivo`, que pide la unidad. La expresión
 *    queda congelada aquí, como en la migración anterior (2026_11_12_210201): no lee el enum.
 * 2. Agrega la exclusión `role_assignments_one_directivo`: un solo directivo por unidad con fechas que se traslapan.
 * 3. Crea el rol de fábrica «Directivo (de fábrica)» (`Role::listLabel()` del puesto, CF-02), que sigue al código, y
 *    se lo da al puesto. Si el puesto ya tiene su rol de fábrica, no lo toca.
 *
 * down() lo regresa a la forma anterior. Se detiene sin cambiar nada si ya hay nombramientos de directivo, porque el
 * código anterior no conoce el puesto: hay que quitarlos antes.
 */
return new class extends Migration
{
    private const string SCOPE_COMPLETE = <<<'SQL'
        case role
            when 'subdireccion' then org_unit_id is not null
            when 'directivo' then org_unit_id is not null
            when 'jefe_departamento' then org_unit_id is not null
            when 'jefe_proyecto_docente' then modality_id is not null and org_unit_id is not null
            when 'coordinador' then modality_id is not null and career_id is not null
            else true
        end
        SQL;

    private const string PREVIOUS_SCOPE_COMPLETE = <<<'SQL'
        case role
            when 'subdireccion' then org_unit_id is not null
            when 'jefe_departamento' then org_unit_id is not null
            when 'jefe_proyecto_docente' then modality_id is not null and org_unit_id is not null
            when 'coordinador' then modality_id is not null and career_id is not null
            else true
        end
        SQL;

    private const string ROLE = 'directivo';

    private const string FACTORY_NAME = 'Directivo (de fábrica)';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('alter table role_assignments drop constraint if exists role_assignments_scope_complete');
            DB::statement('alter table role_assignments add constraint role_assignments_scope_complete check ('.self::SCOPE_COMPLETE.')');

            DB::unprepared(<<<'SQL'
                alter table role_assignments drop constraint if exists role_assignments_one_directivo;
                alter table role_assignments add constraint role_assignments_one_directivo exclude using gist (
                    org_unit_id with =,
                    daterange(valid_from, valid_to, '[]') with &&
                ) where (role = 'directivo');
                SQL);

            if (DB::table('permission_sets')->where('factory_role', self::ROLE)->exists()) {
                return;
            }

            $id = DB::table('permission_sets')->insertGetId([
                'name' => self::FACTORY_NAME,
                'factory_role' => self::ROLE,
                'follows_factory' => true,
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            DB::table('position_permission_sets')->insert(['role' => self::ROLE, 'permission_set_id' => $id]);
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $count = DB::table('role_assignments')->where('role', self::ROLE)->count();

            if ($count > 0) {
                throw new RuntimeException("Hay {$count} nombramientos de directivo; quítalos antes de regresar esta migración, porque el código anterior no conoce el puesto.");
            }

            $ids = DB::table('permission_sets')->where('factory_role', self::ROLE)->pluck('id');

            DB::table('position_permission_sets')->where('role', self::ROLE)->delete();
            DB::table('position_permissions')->where('role', self::ROLE)->delete();
            DB::table('permission_set_permissions')->whereIn('permission_set_id', $ids)->delete();
            DB::table('permission_sets')->whereIn('id', $ids)->delete();

            DB::statement('alter table role_assignments drop constraint if exists role_assignments_one_directivo');
            DB::statement('alter table role_assignments drop constraint if exists role_assignments_scope_complete');
            DB::statement('alter table role_assignments add constraint role_assignments_scope_complete check ('.self::PREVIOUS_SCOPE_COMPLETE.')');
        });
    }
};
