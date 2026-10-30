<?php

use App\Modules\Faculty\Models\TeacherDepartment;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * La adscripción es solo a un departamento: el docente no es de un campus y da clase en cualquiera (confirmado el
 * 30 de octubre de 2026). Quita `campus_id` de `teacher_departments` y la clave pasa a docente y departamento,
 * también contra borradas, así que volver a poner una adscripción quitada la restaura. El índice parcial de la
 * principal (`teacher_departments_one_primary`) no cambia.
 *
 * Antes junta las filas de un docente en el mismo departamento y distinto campus. Se queda una: la que no está
 * borrada; entre ellas, la principal; luego la más antigua (`created_at` y, a igual fecha, el id). Así una
 * adscripción vigente nunca se pierde por una borrada más antigua, y la principal vigente siempre se queda. La que se
 * queda, si está vigente, toma el `created_at` más antiguo de las vigentes de su grupo: es la antigüedad real del
 * docente en el departamento, la que usa «la principal pasa a la más antigua» (RemoveAdscription). Las
 * demás se **eliminan**, no se borran de forma lógica: una borrada con el mismo docente y departamento rompería la
 * clave nueva, que cuenta también las borradas. Quitado el campus son copias de la que se queda y nada las
 * referencia; cada una deja en la bitácora lo que tenía (con su campus) y la que se queda. Ningún docente termina con
 * dos principales: solo se eliminan filas, y el índice parcial ya garantizaba una sola principal sin borrar.
 *
 * down() devuelve la forma de las columnas, con `campus_id` nulo: no puede recuperar el campus ni las filas juntadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        $this->mergeDuplicates();

        Schema::table('teacher_departments', function (Blueprint $table) {
            $table->dropUnique(['teacher_id', 'department_id', 'campus_id']);
            $table->dropIndex(['department_id', 'campus_id']);
            $table->dropIndex(['campus_id']);
            $table->dropForeign(['campus_id']);
            $table->dropColumn('campus_id');

            $table->unique(['teacher_id', 'department_id']);
            $table->index('department_id');
        });
    }

    public function down(): void
    {
        Schema::table('teacher_departments', function (Blueprint $table) {
            $table->dropUnique(['teacher_id', 'department_id']);
            $table->dropIndex(['department_id']);

            $table->foreignUuid('campus_id')->nullable()->constrained();
            $table->unique(['teacher_id', 'department_id', 'campus_id']);
            $table->index(['department_id', 'campus_id']);
            $table->index('campus_id');
        });
    }

    /**
     * Elimina las filas repetidas por docente y departamento, cada una con su entrada en la bitácora.
     */
    private function mergeDuplicates(): void
    {
        $duplicates = DB::select(<<<'SQL'
            select ranked.*, teachers.employee_number, departments.code as department_code, campuses.code as campus_code
            from (
                select teacher_departments.*,
                    row_number() over merged as position,
                    first_value(teacher_departments.id) over merged as kept_id
                from teacher_departments
                window merged as (
                    partition by teacher_id, department_id
                    order by (deleted_at is null) desc, is_primary desc, created_at asc nulls last, id asc
                )
            ) as ranked
            join teachers on teachers.id = ranked.teacher_id
            join departments on departments.id = ranked.department_id
            left join campuses on campuses.id = ranked.campus_id
            where ranked.position > 1
            order by ranked.teacher_id, ranked.department_id, ranked.position
            SQL);

        if ($duplicates === []) {
            return;
        }

        $this->keepOldestDate(array_values(array_unique(array_column($duplicates, 'kept_id'))));

        $now = now();

        foreach (array_chunk($duplicates, 500) as $chunk) {
            DB::table('activity_log')->insert(array_map(fn (object $row): array => [
                'log_name' => config('activitylog.default_log_name', 'default'),
                'description' => "Juntó la adscripción del docente {$row->employee_number} a {$row->department_code} en {$row->campus_code} con la que se queda, porque la adscripción ya no lleva campus",
                'subject_type' => TeacherDepartment::class,
                'subject_id' => $row->id,
                'event' => 'deleted',
                'attribute_changes' => json_encode(['old' => [
                    'department_id' => $row->department_id,
                    'campus_id' => $row->campus_id,
                    'is_primary' => (bool) $row->is_primary,
                    'created_at' => $row->created_at,
                    'deleted_at' => $row->deleted_at,
                ]]),
                'properties' => json_encode(['teacher_id' => $row->teacher_id, 'kept_adscription_id' => $row->kept_id]),
                'created_at' => $now,
                'updated_at' => $now,
            ], $chunk));

            DB::table('teacher_departments')->whereIn('id', array_column($chunk, 'id'))->delete();
        }
    }

    /**
     * A cada fila que se queda y está vigente le pone el `created_at` más antiguo de las vigentes de su grupo (docente
     * y departamento); las borradas no cuentan.
     *
     * @param  list<string>  $keptIds
     */
    private function keepOldestDate(array $keptIds): void
    {
        foreach (array_chunk($keptIds, 500) as $ids) {
            DB::update(
                'update teacher_departments as kept set created_at = live.oldest
                from (
                    select teacher_id, department_id, min(created_at) as oldest
                    from teacher_departments
                    where deleted_at is null
                    group by teacher_id, department_id
                ) as live
                where kept.id in ('.implode(', ', array_fill(0, count($ids), '?')).')
                    and kept.deleted_at is null
                    and live.teacher_id = kept.teacher_id
                    and live.department_id = kept.department_id
                    and live.oldest is not null
                    and (kept.created_at is null or live.oldest < kept.created_at)',
                $ids,
            );
        }
    }
};
