<?php

use App\Modules\AcademicLoad\Models\AcademicLoad;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraer la carga (ronda 2 de la carga por modalidad, ADR-0025): la unidad de la carga pasa a ser el departamento en
 * una modalidad (M1, confirmado el 12 de noviembre de 2026). Sistemas presencial es una sola carga con los grupos de C1
 * y C2; cada grupo conserva su campus.
 *
 * Cómo se funden las que ya existen (MS12, supuesto por confirmar), por periodo, modalidad y departamento:
 * - Si a lo más una tiene historia (filas en `load_transitions` o `load_snapshots`), se queda esa; si ninguna tiene,
 *   la más antigua (`created_at`, luego id). Las demás, sin historia, se borran, y cada una deja su entrada en la
 *   bitácora (evento `deleted`, con la que quedó, su campus y su estado).
 * - Si dos o más tienen historia, se detiene con un RuntimeException que da los ids de cada caso: la historia es
 *   inmutable (HC-18) y no se funde. Todo up() va en una transacción, así que no deja nada.
 *
 * Después la llave única es (periodo, modalidad, departamento), que reemplaza al índice de la ampliación, y
 * `academic_loads.campus_id` se quita con su índice y su llave.
 *
 * down() regresa la columna con el primer campus activo de la modalidad de cada carga por el puente
 * `campuses.modality_id` (o el primero, aunque esté inactivo); si una carga es de una modalidad sin campus, se detiene.
 * Las cargas borradas no regresan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            $this->mergeLoads();

            Schema::table('academic_loads', function (Blueprint $table) {
                $table->dropIndex('academic_loads_term_modality_department_index');
                $table->unique(['term_id', 'modality_id', 'department_id']);
                $table->dropUnique(['term_id', 'campus_id', 'department_id']);
                $table->dropIndex(['campus_id']);
                $table->dropConstrainedForeignId('campus_id');
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('academic_loads', function (Blueprint $table) {
                $table->foreignUuid('campus_id')->nullable()->constrained();
            });

            DB::statement(<<<'SQL'
                update academic_loads set campus_id = (
                    select campuses.id from campuses
                    where campuses.modality_id = academic_loads.modality_id
                    order by campuses.deleted_at is not null, not campuses.is_active, campuses.code
                    limit 1
                )
                SQL);

            $orphans = DB::table('academic_loads')->whereNull('campus_id')->pluck('id')->all();

            if ($orphans !== []) {
                throw new RuntimeException('Estas cargas son de una modalidad sin campus y no se pueden regresar a una por campus: '.implode(', ', $orphans).'.');
            }

            DB::statement('alter table academic_loads alter column campus_id set not null');

            Schema::table('academic_loads', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'modality_id', 'department_id']);
                $table->index(['term_id', 'modality_id', 'department_id'], 'academic_loads_term_modality_department_index');
                $table->unique(['term_id', 'campus_id', 'department_id']);
                $table->index('campus_id');
            });
        });
    }

    /**
     * @throws RuntimeException si en algún periodo, modalidad y departamento dos o más cargas tienen historia
     */
    private function mergeLoads(): void
    {
        $loads = DB::table('academic_loads')
            ->leftJoin('campuses', 'campuses.id', '=', 'academic_loads.campus_id')
            ->select('academic_loads.*', 'campuses.code as campus_code')
            ->selectRaw('exists (select 1 from load_transitions where load_transitions.academic_load_id = academic_loads.id) or exists (select 1 from load_snapshots where load_snapshots.academic_load_id = academic_loads.id) as has_history')
            ->orderBy('academic_loads.created_at')
            ->orderBy('academic_loads.id')
            ->get()
            ->groupBy(fn (object $load): string => "{$load->term_id}|{$load->modality_id}|{$load->department_id}")
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        $conflicts = $loads
            ->filter(fn (Collection $group): bool => $group->where('has_history', true)->count() > 1)
            ->map(fn (Collection $group): string => $group->where('has_history', true)->pluck('id')->implode(' y '))
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay cargas del mismo periodo, modalidad y departamento que tienen historia (transiciones o versiones publicadas) y no se pueden fundir (MS12, HC-18): %s. Vuelve a sembrar la base con demo:seed o decide qué carga se queda antes de migrar.',
                $conflicts->implode('; '),
            ));
        }

        foreach ($loads as $group) {
            $kept = $group->firstWhere('has_history', true) ?? $group->first();

            foreach ($group->reject(fn (object $load): bool => $load->id === $kept->id) as $absorbed) {
                DB::table('academic_loads')->where('id', $absorbed->id)->delete();

                activity()
                    ->performedOn((new AcademicLoad)->newFromBuilder(['id' => $absorbed->id]))
                    ->event('deleted')
                    ->withProperties([
                        'kept' => $kept->id,
                        'campus' => $absorbed->campus_code,
                        'status' => $absorbed->status,
                        'term_id' => $absorbed->term_id,
                        'department_id' => $absorbed->department_id,
                    ])
                    ->log("Fundió la carga de {$absorbed->campus_code} en la del departamento en su modalidad (M1, MS12); se queda {$kept->id}");
            }
        }
    }
};
