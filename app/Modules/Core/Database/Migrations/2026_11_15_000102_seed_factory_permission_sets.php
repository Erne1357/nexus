<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Los roles de fábrica (CF-02): uno por puesto salvo el admin, «<puesto> (de fábrica)», que sigue lo que el código
 * concede al puesto (`follows_factory`), asignado a su puesto. Así, el día del despliegue cada puesto puede
 * exactamente lo de antes (lo congela `tests/Fixtures/Authorization/matrix.json`).
 *
 * Las claves y los nombres van como texto, no con el enum `Role`: la migración debe correr igual aunque el enum cambie
 * después. Los nombres son `Role::listLabel()` de cada puesto (lo revisa GrantTableTest). Si el puesto ya tiene su rol
 * de fábrica, no hace nada con él.
 */
return new class extends Migration
{
    /** @var array<string, string> puesto => nombre del rol de fábrica */
    private const array FACTORY = [
        'subdireccion' => 'Subdirección Académica (de fábrica)',
        'jefe_departamento' => 'Jefe de departamento (de fábrica)',
        'jefe_proyecto_docente' => 'Jefe de proyecto docente (de fábrica)',
        'coordinador' => 'Coordinador de carrera (de fábrica)',
        'docente' => 'Docente (de fábrica)',
        'alumno' => 'Alumno (de fábrica)',
    ];

    public function up(): void
    {
        DB::transaction(function (): void {
            foreach (self::FACTORY as $role => $name) {
                if (DB::table('permission_sets')->where('factory_role', $role)->exists()) {
                    continue;
                }

                $id = DB::table('permission_sets')->insertGetId([
                    'name' => $name,
                    'factory_role' => $role,
                    'follows_factory' => true,
                    'is_active' => true,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);

                DB::table('position_permission_sets')->insert(['role' => $role, 'permission_set_id' => $id]);
            }
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            $ids = DB::table('permission_sets')->whereIn('factory_role', array_keys(self::FACTORY))->pluck('id');

            DB::table('position_permission_sets')->whereIn('permission_set_id', $ids)->delete();
            DB::table('permission_set_permissions')->whereIn('permission_set_id', $ids)->delete();
            DB::table('permission_sets')->whereIn('id', $ids)->delete();
        });
    }
};
