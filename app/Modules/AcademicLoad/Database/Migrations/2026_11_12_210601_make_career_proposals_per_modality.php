<?php

use App\Modules\AcademicLoad\Models\CareerProposal;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Contraer la propuesta de carrera (ronda 2 de la carga por modalidad, ADR-0025): la propuesta es una por carrera y
 * modalidad, y lleva una fila por materia y campus (M6, confirmado el 12 de noviembre de 2026). Sistemas presencial es
 * una sola propuesta con las filas de C1 y C2; cada fila conserva su campus.
 *
 * Cómo se funden las que ya existen (MS12, supuesto por confirmar), por periodo, modalidad y carrera:
 * - Tiene historia la propuesta enviada (`submitted_at`) o con alguna parte decidida (aceptada o regresada en
 *   `proposal_reviews`).
 * - Si a lo más una tiene historia, se queda esa; si ninguna, la más antigua (`created_at`, luego id). Las filas de las
 *   demás pasan a la que se queda con su campus, y sus alumnos posibles se suman a los de ella (los alumnos de un campus
 *   no son de otro). Sus partes, todas sin decidir, pasan a la que se queda si esta no tiene la del departamento; si ya
 *   la tiene, se borran. Cada propuesta que se borra deja su entrada en la bitácora (evento `deleted`, con la que quedó,
 *   su campus y su estado).
 * - Si dos o más tienen historia, se detiene con un RuntimeException que da los ids de cada caso: la historia es
 *   inmutable (HC-18) y no se funde. Todo up() va en una transacción, así que no deja nada.
 *
 * Después la llave única de `career_proposals` es (periodo, modalidad, carrera), que reemplaza al índice de la
 * ampliación, y `career_proposals.campus_id` se quita con su índice y su llave; las filas son únicas por (propuesta,
 * materia, campus), con los nulos como iguales (una modalidad sin salones lleva una sola columna sin campus, MS5), y
 * `proposal_items.campus_id` lleva índice. También agrega el índice de los alumnos por carrera, modalidad y campus, con
 * el que se cuentan los alumnos posibles de cada columna.
 *
 * down() regresa la columna con el campus de la primera fila de cada propuesta (por clave del campus) o, sin filas, el
 * primer campus activo de su modalidad por el puente `campuses.modality_id`; si una propuesta tiene filas de varios
 * campus o queda sin campus, se detiene. Las propuestas borradas no regresan.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::transaction(function (): void {
            Schema::table('proposal_items', function (Blueprint $table) {
                $table->dropUnique(['career_proposal_id', 'subject_id']);
                $table->index('campus_id');
            });

            DB::statement('alter table proposal_items add constraint proposal_items_career_proposal_id_subject_id_campus_id_unique unique nulls not distinct (career_proposal_id, subject_id, campus_id)');

            $this->mergeProposals();

            Schema::table('career_proposals', function (Blueprint $table) {
                $table->dropIndex('career_proposals_term_modality_career_index');
                $table->unique(['term_id', 'modality_id', 'career_id']);
                $table->dropUnique(['term_id', 'campus_id', 'career_id']);
                $table->dropIndex(['campus_id']);
                $table->dropConstrainedForeignId('campus_id');
            });

            Schema::table('students', function (Blueprint $table) {
                $table->index(['career_id', 'modality_id', 'campus_id']);
            });
        });
    }

    public function down(): void
    {
        DB::transaction(function (): void {
            Schema::table('students', function (Blueprint $table) {
                $table->dropIndex(['career_id', 'modality_id', 'campus_id']);
            });

            $mixed = DB::table('proposal_items')
                ->groupBy('career_proposal_id')
                ->havingRaw('count(distinct campus_id) > 1 or (count(*) > 0 and count(campus_id) = 0)')
                ->pluck('career_proposal_id')
                ->all();

            if ($mixed !== []) {
                throw new RuntimeException('Estas propuestas tienen filas de varios campus o sin campus y no se pueden regresar a una por campus: '.implode(', ', $mixed).'.');
            }

            DB::statement('alter table proposal_items drop constraint proposal_items_career_proposal_id_subject_id_campus_id_unique');

            Schema::table('proposal_items', function (Blueprint $table) {
                $table->dropIndex(['campus_id']);
                $table->unique(['career_proposal_id', 'subject_id']);
            });

            Schema::table('career_proposals', function (Blueprint $table) {
                $table->foreignUuid('campus_id')->nullable()->constrained();
            });

            DB::statement(<<<'SQL'
                update career_proposals set campus_id = coalesce(
                    (select proposal_items.campus_id from proposal_items
                        join campuses on campuses.id = proposal_items.campus_id
                        where proposal_items.career_proposal_id = career_proposals.id
                        order by campuses.code limit 1),
                    (select campuses.id from campuses
                        where campuses.modality_id = career_proposals.modality_id
                        order by campuses.deleted_at is not null, not campuses.is_active, campuses.code
                        limit 1)
                )
                SQL);

            $orphans = DB::table('career_proposals')->whereNull('campus_id')->pluck('id')->all();

            if ($orphans !== []) {
                throw new RuntimeException('Estas propuestas son de una modalidad sin campus y no se pueden regresar a una por campus: '.implode(', ', $orphans).'.');
            }

            DB::statement('alter table career_proposals alter column campus_id set not null');

            Schema::table('career_proposals', function (Blueprint $table) {
                $table->dropUnique(['term_id', 'modality_id', 'career_id']);
                $table->index(['term_id', 'modality_id', 'career_id'], 'career_proposals_term_modality_career_index');
                $table->unique(['term_id', 'campus_id', 'career_id']);
                $table->index('campus_id');
            });
        });
    }

    /**
     * @throws RuntimeException si en algún periodo, modalidad y carrera dos o más propuestas tienen historia
     */
    private function mergeProposals(): void
    {
        $groups = DB::table('career_proposals')
            ->leftJoin('campuses', 'campuses.id', '=', 'career_proposals.campus_id')
            ->select('career_proposals.*', 'campuses.code as campus_code')
            ->selectRaw("career_proposals.submitted_at is not null or exists (select 1 from proposal_reviews where proposal_reviews.career_proposal_id = career_proposals.id and proposal_reviews.status in ('accepted', 'returned')) as has_history")
            ->orderBy('career_proposals.created_at')
            ->orderBy('career_proposals.id')
            ->get()
            ->groupBy(fn (object $proposal): string => "{$proposal->term_id}|{$proposal->modality_id}|{$proposal->career_id}")
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        $conflicts = $groups
            ->filter(fn (Collection $group): bool => $group->where('has_history', true)->count() > 1)
            ->map(fn (Collection $group): string => $group->where('has_history', true)->pluck('id')->implode(' y '))
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay propuestas de la misma carrera, periodo y modalidad que tienen historia (envío o partes decididas) y no se pueden fundir (MS12, HC-18): %s. Vuelve a sembrar la base con demo:seed o decide qué propuesta se queda antes de migrar.',
                $conflicts->implode('; '),
            ));
        }

        foreach ($groups as $group) {
            $kept = $group->firstWhere('has_history', true) ?? $group->first();

            foreach ($group->reject(fn (object $proposal): bool => $proposal->id === $kept->id) as $absorbed) {
                $this->absorb($absorbed, $kept);
            }
        }
    }

    /**
     * Pasa las filas, los alumnos posibles y las partes sin decidir de `$absorbed` a `$kept`, y la borra con su entrada
     * en la bitácora.
     */
    private function absorb(object $absorbed, object $kept): void
    {
        DB::table('proposal_items')->where('career_proposal_id', $absorbed->id)->update(['career_proposal_id' => $kept->id]);
        DB::table('career_proposals')->where('id', $kept->id)->increment('eligible_students', (int) $absorbed->eligible_students);

        $keptDepartments = DB::table('proposal_reviews')->where('career_proposal_id', $kept->id)->pluck('department_id')->all();
        $parts = DB::table('proposal_reviews')->where('career_proposal_id', $absorbed->id)->get(['id', 'department_id']);
        $merged = $parts->filter(fn (object $part): bool => in_array($part->department_id, $keptDepartments, true));

        DB::table('proposal_reviews')->whereIn('id', $merged->pluck('id')->all())->delete();
        DB::table('proposal_reviews')->where('career_proposal_id', $absorbed->id)->update(['career_proposal_id' => $kept->id]);
        DB::table('career_proposals')->where('id', $absorbed->id)->delete();

        activity()
            ->performedOn((new CareerProposal)->newFromBuilder(['id' => $absorbed->id]))
            ->event('deleted')
            ->withProperties([
                'kept' => $kept->id,
                'campus' => $absorbed->campus_code,
                'status' => $absorbed->status,
                'term_id' => $absorbed->term_id,
                'career_id' => $absorbed->career_id,
                'eligible_students' => (int) $absorbed->eligible_students,
                'merged_reviews' => $merged->pluck('id')->values()->all(),
            ])
            ->log("Fundió la propuesta de {$absorbed->campus_code} en la de la carrera en su modalidad (M6, MS12); se queda {$kept->id}");
    }
};
