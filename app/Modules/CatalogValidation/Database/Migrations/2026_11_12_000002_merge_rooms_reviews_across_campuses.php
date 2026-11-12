<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una sola revisión de salones por departamento y periodo, para todos los campus (decisión 6, confirmada el 12 de
 * noviembre de 2026; docs/dominio/modelo-de-datos.md, "CatalogValidation"). Antes había una por campus (D2).
 *
 * Cómo se funden las que ya existen (CV-S11, supuesto por confirmar), por periodo y departamento:
 * - Se queda la de estado más avanzado (validada, luego en revisión, luego pendiente) y, a igual estado, la de id menor
 *   (la más antigua). Conserva su estado, quién y cuándo la validó.
 * - Las observaciones y las marcas de revisado de las demás pasan a la que queda; una marca del mismo registro que ya
 *   estaba en la que queda no se repite.
 * - La foto es la unión de las fotos de todas (sin repetir registros), porque ahora la revisión cubre los salones de
 *   todos los campus; las notas se juntan, una por renglón, empezando por las de la que queda.
 * - Las demás se borran: una revisión no es catálogo. Su bitácora se queda como está.
 * - La que queda pierde su campus, y el CHECK `catalog_reviews_campus_check` ahora exige que ninguna revisión lo tenga.
 *
 * Es una corrección de esquema y no deja bitácora. down() regresa el CHECK de antes solo si ninguna revisión de salones
 * quedó sin campus, porque no hay de dónde sacarlo.
 */
return new class extends Migration
{
    private const array RANK = ['validated' => 0, 'in_review' => 1, 'pending' => 2];

    public function up(): void
    {
        DB::statement('alter table catalog_reviews drop constraint catalog_reviews_campus_check');

        $groups = DB::table('catalog_reviews')
            ->where('catalog', 'rooms')
            ->select('term_id', 'department_id')
            ->groupBy('term_id', 'department_id')
            ->get();

        foreach ($groups as $group) {
            $this->merge($group->term_id, $group->department_id);
        }

        DB::statement('alter table catalog_reviews add constraint catalog_reviews_campus_check check (campus_id is null)');
    }

    public function down(): void
    {
        if (DB::table('catalog_reviews')->where('catalog', 'rooms')->whereNull('campus_id')->exists()) {
            throw new RuntimeException('Hay revisiones de salones sin campus: no se puede regresar a una por campus.');
        }

        DB::statement('alter table catalog_reviews drop constraint catalog_reviews_campus_check');
        DB::statement("alter table catalog_reviews add constraint catalog_reviews_campus_check check ((catalog = 'rooms') = (campus_id is not null))");
    }

    private function merge(string $termId, string $departmentId): void
    {
        $reviews = DB::table('catalog_reviews')
            ->where('catalog', 'rooms')
            ->where('term_id', $termId)
            ->where('department_id', $departmentId)
            ->orderBy('id')
            ->get()
            ->sortBy(fn (object $review): int => self::RANK[$review->status] ?? 3)
            ->values();

        $kept = $reviews->first();
        $others = $reviews->slice(1)->pluck('id')->all();
        $snapshot = $this->unionOfSnapshots($reviews->pluck('snapshot')->all());
        $notes = $reviews->pluck('notes')->filter(fn (?string $note): bool => $note !== null && trim($note) !== '')->unique()->implode("\n");

        if ($others !== []) {
            DB::table('catalog_review_flags')->whereIn('review_id', $others)->update(['review_id' => $kept->id]);

            DB::table('catalog_review_marks')
                ->whereIn('catalog_review_id', $others)
                ->whereIn('record_id', DB::table('catalog_review_marks')->where('catalog_review_id', $kept->id)->select('record_id'))
                ->delete();
            DB::table('catalog_review_marks')
                ->whereIn('id', DB::table('catalog_review_marks')->whereIn('catalog_review_id', $others)->selectRaw('distinct on (record_id) id')->orderBy('record_id')->orderBy('id'))
                ->update(['catalog_review_id' => $kept->id]);
            DB::table('catalog_review_marks')->whereIn('catalog_review_id', $others)->delete();

            DB::table('catalog_reviews')->whereIn('id', $others)->delete();
        }

        DB::table('catalog_reviews')->where('id', $kept->id)->update([
            'campus_id' => null,
            'snapshot' => $snapshot === null ? null : json_encode($snapshot),
            'notes' => $notes === '' ? null : $notes,
        ]);
    }

    /**
     * @param  list<?string>  $snapshots  las fotos en JSON, la de la que queda primero
     * @return ?list<array<string, mixed>>
     */
    private function unionOfSnapshots(array $snapshots): ?array
    {
        $records = null;

        foreach ($snapshots as $snapshot) {
            if ($snapshot === null) {
                continue;
            }

            foreach (json_decode($snapshot, true) as $record) {
                $records ??= [];
                $records[$record['id']] ??= $record;
            }
        }

        return $records === null ? null : array_values($records);
    }
};
