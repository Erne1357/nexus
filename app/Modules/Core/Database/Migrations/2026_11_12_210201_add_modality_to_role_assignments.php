<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La modalidad en los nombramientos (AZ-S17; M5 y M9, confirmados el 12 de noviembre de 2026). Es parte del paso
 * «ampliar» de la carga por modalidad: el coordinador va sobre carrera y modalidad, y el jefe de proyecto docente sobre
 * departamento y modalidad; el campus queda opcional en los dos (sin campus, todos los de su modalidad).
 *
 * up(), todo en una transacción:
 * 1. Revisión previa: si un coordinador o un jefe de proyecto docente (vigente o no) tiene un campus cuyo puente
 *    `campuses.modality_id` no es `PRES`, se detiene con un RuntimeException que lista sus ids y no escribe nada.
 * 2. Agrega `modality_id` (FK nullable a `modalities`).
 * 3. Los coordinadores y jefes de proyecto docente que ya existen reciben `PRES` y **conservan su campus**: su alcance
 *    de hoy no cambia. Los demás puestos quedan sin modalidad, que es «todas las activas».
 * 4. Reemplaza el CHECK `role_assignments_scope_complete` (coordinador: modalidad y carrera; jefe de proyecto docente:
 *    modalidad y unidad; subdirección y jefe de departamento: unidad; los demás, nada) y el de
 *    `role_assignments_admin_unscoped` (el admin tampoco lleva modalidad). La expresión queda congelada aquí: la
 *    migración no lee el enum.
 * 5. Reemplaza la exclusión `role_assignments_jpd_no_duplicate` (la creó la migración `2026_11_07_000001` de Academic):
 *    la misma persona no es dos veces jefe de proyecto docente del mismo departamento, modalidad y campus (o sin
 *    campus) con fechas que se traslapan. El campus nulo entra como el uuid de ceros para que dos sin campus choquen.
 *
 * down() regresa la forma anterior: los CHECK y la exclusión sin modalidad, y quita la columna. Falla si ya hay un
 * coordinador o un jefe de proyecto docente sin campus, porque la forma anterior lo pedía.
 */
return new class extends Migration
{
    private const string SCOPE_COMPLETE = <<<'SQL'
        case role
            when 'subdireccion' then org_unit_id is not null
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
            when 'jefe_proyecto_docente' then campus_id is not null and org_unit_id is not null
            when 'coordinador' then campus_id is not null and career_id is not null
            else true
        end
        SQL;

    public function up(): void
    {
        DB::transaction(function (): void {
            $presencial = DB::table('modalities')->where('code', 'PRES')->value('id')
                ?? throw new RuntimeException('Falta la modalidad PRES: corre antes la migración del catálogo de modalidades.');

            $this->ensureCampusesArePresencial($presencial);

            Schema::table('role_assignments', function (Blueprint $table) {
                $table->foreignUuid('modality_id')->nullable()->constrained('modalities');
            });

            DB::table('role_assignments')
                ->whereIn('role', ['coordinador', 'jefe_proyecto_docente'])
                ->update(['modality_id' => $presencial]);

            DB::unprepared(<<<'SQL'
                alter table role_assignments drop constraint if exists role_assignments_admin_unscoped;
                alter table role_assignments add constraint role_assignments_admin_unscoped check (
                    role <> 'admin' or (campus_id is null and org_unit_id is null and career_id is null and modality_id is null)
                );

                alter table role_assignments drop constraint if exists role_assignments_jpd_no_duplicate;
                alter table role_assignments add constraint role_assignments_jpd_no_duplicate exclude using gist (
                    user_id with =,
                    org_unit_id with =,
                    modality_id with =,
                    (coalesce(campus_id, '00000000-0000-0000-0000-000000000000'::uuid)) with =,
                    daterange(valid_from, valid_to, '[]') with &&
                ) where (role = 'jefe_proyecto_docente');
                SQL);

            DB::statement('alter table role_assignments drop constraint if exists role_assignments_scope_complete');
            DB::statement('alter table role_assignments add constraint role_assignments_scope_complete check ('.self::SCOPE_COMPLETE.')');
        });
    }

    public function down(): void
    {
        DB::statement('alter table role_assignments drop constraint if exists role_assignments_scope_complete');
        DB::statement('alter table role_assignments add constraint role_assignments_scope_complete check ('.self::PREVIOUS_SCOPE_COMPLETE.')');

        DB::unprepared(<<<'SQL'
            alter table role_assignments drop constraint if exists role_assignments_jpd_no_duplicate;
            alter table role_assignments add constraint role_assignments_jpd_no_duplicate exclude using gist (
                user_id with =,
                org_unit_id with =,
                campus_id with =,
                daterange(valid_from, valid_to, '[]') with &&
            ) where (role = 'jefe_proyecto_docente');

            alter table role_assignments drop constraint if exists role_assignments_admin_unscoped;
            alter table role_assignments add constraint role_assignments_admin_unscoped check (
                role <> 'admin' or (campus_id is null and org_unit_id is null and career_id is null)
            );
            SQL);

        Schema::table('role_assignments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('modality_id');
        });
    }

    /**
     * Los coordinadores y jefes de proyecto docente con un campus cuya modalidad (el puente `campuses.modality_id`,
     * también de un campus borrado) no es Presencial no pueden recibir `PRES` sin cambiar su alcance: se detiene antes
     * de escribir y lista sus ids.
     *
     * @throws RuntimeException
     */
    private function ensureCampusesArePresencial(string $presencial): void
    {
        $ids = DB::table('role_assignments')
            ->join('campuses', 'campuses.id', '=', 'role_assignments.campus_id')
            ->whereIn('role_assignments.role', ['coordinador', 'jefe_proyecto_docente'])
            ->whereRaw('campuses.modality_id is distinct from ?::uuid', [$presencial])
            ->orderBy('role_assignments.id')
            ->pluck('role_assignments.id');

        if ($ids->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay %d nombramientos de coordinador o de jefe de proyecto docente en un campus que no es presencial: %s. No se les puede dar la modalidad Presencial sin cambiar su alcance; termínalos o muévelos a un campus presencial antes de migrar.',
                $ids->count(),
                $ids->implode(', '),
            ));
        }
    }
};
