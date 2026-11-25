<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * El puesto `personal` («Personal de área», CO-01 y CO-02, confirmado el 24 de noviembre de 2026): el personal de una
 * unidad que no es departamento académico, varios por unidad. Se nombra sobre su unidad, sin campus ni modalidad.
 *
 * up(), en una transacción:
 * 1. Agrega el CHECK `role_assignments_scope_complete_personal`: un nombramiento de `personal` pide la unidad, como
 *    `role_assignments_scope_complete` pide la de los demás puestos. Va en un CHECK aparte, sin reescribir aquel, para
 *    que la migración solo agregue (expand/contract, ADR-0033): el release anterior nunca escribe `personal`, y volver a
 *    él no exige restaurar el respaldo. El nombre empieza igual que el del otro CHECK, así que el error lo nombra.
 * 2. Crea el rol de fábrica «Personal de área (de fábrica)» (`Role::listLabel()` del puesto, CF-02), que sigue al
 *    código, y se lo da al puesto. Es catálogo público de permisos, como el del directivo (2026_11_19_000001). Si el
 *    puesto ya tiene su rol de fábrica, no lo toca.
 *
 * down() lo regresa a la forma anterior. Se detiene sin cambiar nada si ya hay nombramientos de personal de área,
 * porque el código anterior no conoce el puesto: hay que quitarlos antes. Volver al release anterior sin regresar la
 * migración (`nexus rollback`), como con el directivo, deja filas que ese código no conoce: el rol de fábrica
 * (`permission_sets.factory_role = 'personal'`, que PermissionSet convierte a Role) y, si ya hay, los nombramientos y
 * `users.last_role` de `personal`. Esas filas no se ignoran: los casts de `RoleAssignment.role` y `User.last_role` del
 * código anterior lanzan `ValueError` en «Personas y puestos», en el panel del organigrama de Comunicación y al iniciar
 * sesión esas personas. Antes de volver, termina esos nombramientos y pon en null su `last_role`, o restaura el
 * respaldo (docs/operacion/despliegue.md, «Volver a la versión anterior»).
 */
return new class extends Migration
{
    private const string ROLE = 'personal';

    private const string FACTORY_NAME = 'Personal de área (de fábrica)';

    private const string CHECK = 'role_assignments_scope_complete_personal';

    public function up(): void
    {
        DB::transaction(function (): void {
            DB::statement('alter table role_assignments add constraint '.self::CHECK." check (role <> 'personal' or org_unit_id is not null)");

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
                throw new RuntimeException("Hay {$count} nombramientos de personal de área; quítalos antes de regresar esta migración, porque el código anterior no conoce el puesto.");
            }

            $ids = DB::table('permission_sets')->where('factory_role', self::ROLE)->pluck('id');

            DB::table('position_permission_sets')->where('role', self::ROLE)->delete();
            DB::table('position_permissions')->where('role', self::ROLE)->delete();
            DB::table('permission_set_permissions')->whereIn('permission_set_id', $ids)->delete();
            DB::table('permission_sets')->whereIn('id', $ids)->delete();

            DB::statement('alter table role_assignments drop constraint if exists '.self::CHECK);
        });
    }
};
