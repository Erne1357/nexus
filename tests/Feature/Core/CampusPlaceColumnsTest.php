<?php

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
| M1 y MS6 (supuesto por confirmar): la carga es de departamento × modalidad y se lee a nivel de modalidad; el campus es
| solo el lugar (M2, MS3). Ninguna tabla de la carga guarda un `campus_id` de alcance: lo llevan solo las tablas donde
| el campus es el lugar de algo (un edificio, un salón, un grupo, un alumno) o el límite de un nombramiento. Una tabla
| nueva con `campus_id` tiene que agregarse aquí con su motivo.
*/

/**
 * Las tablas que llevan `campus_id` y por qué es un lugar (o el límite de un nombramiento), no el alcance de la carga.
 *
 * @return array<string, string>
 */
function campusPlaceTables(): array
{
    return [
        'buildings' => 'el campus donde está el edificio',
        'rooms' => 'el campus donde está el salón (copia del edificio, para la llave compuesta con el grupo)',
        'subject_room_defaults' => 'el salón sugerido de una materia en cada campus',
        'career_campus' => 'dónde se ofrece la carrera (HC-13, MS5)',
        'students' => 'el campus donde estudia el alumno (MS10)',
        'groups' => 'el campus donde se da el grupo (M8; nulo en una modalidad sin salones, MS4)',
        'group_sessions' => 'copia del campus del grupo, para que el salón de la sesión sea de ese campus (llave compuesta)',
        'schedule_conflicts' => 'copia del campus del grupo, para el filtro de campus del tablero de choques',
        'proposal_items' => 'la columna de campus de la propuesta: dónde se piden los grupos (M6, M7)',
        'interdepartmental_agreements' => 'solo en el préstamo de salón: el campus del salón (nulo en el grupo compartido)',
        'teacher_subject_history' => 'dónde se dio el grupo que impartió el docente',
        'import_batches' => 'el campus del archivo importado, si lo tiene',
        'role_assignments' => 'el límite de un nombramiento con campus (M5, M9): su lugar de trabajo, no el de la carga',
        'catalog_reviews' => 'siempre nulo (CHECK `catalog_reviews_campus_check`): las revisiones son del departamento',
        'department_heads' => 'tabla vieja de solo lectura, reemplazada por role_assignments (ADR-0018)',
    ];
}

/**
 * Las tablas de la carga, por modalidad (M1): ninguna lleva campus.
 *
 * @return list<string>
 */
function loadTablesByModality(): array
{
    return [
        'academic_loads', 'load_transitions', 'load_snapshots', 'career_proposals', 'proposal_reviews', 'term_openings',
        'term_stages', 'staffing_rankings', 'group_teacher_assignments', 'change_requests', 'teacher_term_loads',
    ];
}

it('MS3: solo las tablas donde el campus es un lugar llevan campus_id, cada una con su motivo', function () {
    $tables = collect(DB::select(<<<'SQL'
        select table_name from information_schema.columns
        where column_name = 'campus_id' and table_schema = any (current_schemas(false))
        order by table_name
        SQL))->pluck('table_name')->all();

    expect(array_values(array_diff($tables, array_keys(campusPlaceTables()))))->toBe([]);
});

it('M1: ninguna tabla de la carga tiene campus_id de alcance', function () {
    foreach (loadTablesByModality() as $table) {
        expect(Schema::hasTable($table))->toBeTrue("falta la tabla {$table}")
            ->and(Schema::hasColumn($table, 'campus_id'))->toBeFalse("{$table} tiene campus_id");
    }
});

it('MS3: el campus es solo el lugar: sin modalidad, sin perfil de flujo y sin el puente al catálogo', function () {
    foreach (['modality', 'settings', 'modality_id'] as $column) {
        expect(Schema::hasColumn('campuses', $column))->toBeFalse("campuses.{$column} sigue");
    }
});
