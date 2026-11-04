<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * 3.P9: la base rechaza un nombramiento al que le falta una columna que su puesto pide (`Role::scopeColumns()`, D2).
 * Hasta ahora solo lo filtraba `AuthorizationSnapshots`; con el CHECK ya no se puede escribir uno incompleto.
 *
 * La expresión queda congelada aquí (la migración no lee el enum). Pide solo lo que falta: una columna de más no se
 * rechaza, porque solo acota (D3):
 * - `subdireccion` y `jefe_departamento`: unidad.
 * - `jefe_proyecto_docente`: unidad y campus. Supuesto por confirmar (Q1): si se decide que es de todo el Tec, una
 *   migración posterior relaja el campus.
 * - `coordinador`: campus y carrera.
 * - `admin` y `docente`: nada.
 *
 * up() revisa antes los renglones que ya violan la expresión (Q2, confirmado el 4 de noviembre de 2026). Si hay, se
 * detiene con un RuntimeException que lista sus ids y no toca ningún dato: no termina ni borra nombramientos.
 *
 * down() quita el CHECK.
 */
return new class extends Migration
{
    private const string EXPRESSION = <<<'SQL'
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
        $incomplete = DB::table('role_assignments')
            ->whereRaw('not ('.self::EXPRESSION.')')
            ->orderBy('id')
            ->pluck('id');

        if ($incomplete->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay %d nombramientos que no llenan lo que pide su puesto (role_assignments_scope_complete): %s. No cuentan para nadie; corrígelos o termínalos antes de migrar.',
                $incomplete->count(),
                $incomplete->implode(', '),
            ));
        }

        DB::statement('alter table role_assignments add constraint role_assignments_scope_complete check ('.self::EXPRESSION.')');
    }

    public function down(): void
    {
        DB::statement('alter table role_assignments drop constraint if exists role_assignments_scope_complete');
    }
};
