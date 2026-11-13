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
 * Los alumnos posibles de cada campus se guardan junto al total, congelados (`eligible_students_by_campus`, jsonb
 * `{id del campus: alumnos}`, con la llave `none` para la columna sin campus): cada propuesta de antes era de un campus,
 * así que su total es el de ese campus.
 *
 * Cómo se funden las que ya existen (MS12, supuesto por confirmar), por periodo, modalidad y carrera:
 * - Tiene historia la propuesta que ya no está en borrador, la enviada (`submitted_at`) o la que tiene alguna parte
 *   decidida (aceptada o regresada en `proposal_reviews`).
 * - Si a lo más una tiene historia, se queda esa; si ninguna, la más antigua (`created_at`, luego id). Las filas de las
 *   demás pasan a la que se queda con su campus, y sus alumnos posibles se suman a los de ella, también los de cada
 *   campus (los alumnos de un campus no son de otro). Sus partes, todas sin decidir, pasan a la que se queda si esta no
 *   tiene la del departamento; si ya la tiene, se borran. Cada propuesta que se borra deja su entrada en la bitácora
 *   (evento `deleted`, con la que quedó, su campus y su estado).
 * - Se detiene con un RuntimeException que da la carrera, el periodo y los ids de cada caso si dos o más tienen
 *   historia (la historia es inmutable, HC-18, y no se funde), o si la que tiene historia absorbería otra que pide
 *   grupos: esas filas quedarían en una propuesta ya enviada o aceptada, sin parte que las decida (MS12). Todo up() va
 *   en una transacción, así que no deja nada.
 *
 * Después la llave única de `career_proposals` es (periodo, modalidad, carrera), que reemplaza al índice de la
 * ampliación, y `career_proposals.campus_id` se quita con su índice y su llave; las filas son únicas por (propuesta,
 * materia, campus), con los nulos como iguales (una modalidad sin salones lleva una sola columna sin campus, MS5), y
 * `proposal_items.campus_id` lleva índice. También agrega el índice de los alumnos por carrera, modalidad y campus, con
 * el que se cuentan los alumnos posibles de cada columna.
 *
 * down() regresa la columna con el campus de la primera fila de cada propuesta (por clave del campus) o, sin filas, el
 * primer campus activo de su modalidad por el puente `campuses.modality_id`, y quita los alumnos posibles por campus; si
 * una propuesta tiene filas de varios campus o queda sin campus, se detiene. Las propuestas borradas no regresan.
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

            Schema::table('career_proposals', function (Blueprint $table) {
                $table->jsonb('eligible_students_by_campus')->default('{}');
            });

            DB::statement('update career_proposals set eligible_students_by_campus = jsonb_build_object(campus_id::text, eligible_students)');

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

            Schema::table('career_proposals', function (Blueprint $table) {
                $table->dropColumn('eligible_students_by_campus');
            });

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
     * @throws RuntimeException si en algún periodo, modalidad y carrera dos o más propuestas tienen historia, o la que la
     *                          tiene absorbería otra que pide grupos
     */
    private function mergeProposals(): void
    {
        $groups = DB::table('career_proposals')
            ->leftJoin('campuses', 'campuses.id', '=', 'career_proposals.campus_id')
            ->select('career_proposals.*', 'campuses.code as campus_code')
            ->selectRaw("career_proposals.status <> 'draft' or career_proposals.submitted_at is not null or exists (select 1 from proposal_reviews where proposal_reviews.career_proposal_id = career_proposals.id and proposal_reviews.status in ('accepted', 'returned')) as has_history")
            ->selectRaw('exists (select 1 from proposal_items where proposal_items.career_proposal_id = career_proposals.id and proposal_items.requested_groups > 0) as requests_groups')
            ->orderBy('career_proposals.created_at')
            ->orderBy('career_proposals.id')
            ->get()
            ->groupBy(fn (object $proposal): string => "{$proposal->term_id}|{$proposal->modality_id}|{$proposal->career_id}")
            ->filter(fn (Collection $group): bool => $group->count() > 1);

        $conflicts = $groups
            ->map(fn (Collection $group): ?string => $this->conflictOf($group))
            ->filter()
            ->values();

        if ($conflicts->isNotEmpty()) {
            throw new RuntimeException(sprintf(
                'Hay propuestas de la misma carrera, periodo y modalidad que no se pueden fundir (MS12, HC-18): %s. Vuelve a sembrar la base con demo:seed o decide qué propuesta se queda antes de migrar.',
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
     * Por qué no se funden las propuestas de una carrera en un periodo y una modalidad, o null si se pueden fundir: dos o
     * más tienen historia, o la que tiene historia absorbería otra que pide grupos.
     *
     * @param  Collection<int, object>  $group
     */
    private function conflictOf(Collection $group): ?string
    {
        $first = $group->first();
        $where = "carrera {$first->career_id} en el periodo {$first->term_id}";
        $withHistory = $group->where('has_history', true);

        if ($withHistory->count() > 1) {
            return "{$where}: {$withHistory->pluck('id')->implode(' y ')} tienen historia (envío o partes decididas)";
        }

        $kept = $withHistory->first();
        $requesting = $kept === null ? collect() : $group->reject(fn (object $proposal): bool => $proposal->id === $kept->id)->where('requests_groups', true);

        if ($requesting->isNotEmpty()) {
            return "{$where}: {$kept->id} tiene historia y absorbería {$requesting->pluck('id')->implode(' y ')}, que pide grupos";
        }

        return null;
    }

    /**
     * Pasa las filas, los alumnos posibles (el total y los de cada campus) y las partes sin decidir de `$absorbed` a
     * `$kept`, y la borra con su entrada en la bitácora.
     */
    private function absorb(object $absorbed, object $kept): void
    {
        DB::table('proposal_items')->where('career_proposal_id', $absorbed->id)->update(['career_proposal_id' => $kept->id]);

        $split = json_decode((string) DB::table('career_proposals')->where('id', $kept->id)->value('eligible_students_by_campus'), true) ?: [];

        foreach (json_decode((string) $absorbed->eligible_students_by_campus, true) ?: [] as $campus => $students) {
            $split[$campus] = ($split[$campus] ?? 0) + (int) $students;
        }

        DB::table('career_proposals')->where('id', $kept->id)->update([
            'eligible_students' => DB::raw('eligible_students + '.(int) $absorbed->eligible_students),
            'eligible_students_by_campus' => json_encode($split, JSON_FORCE_OBJECT),
        ]);

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
