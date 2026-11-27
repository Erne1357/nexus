<?php

use App\Modules\CatalogValidation\Models\CatalogReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Una sola revisión de salones por departamento y periodo, para todos los campus (decisión 6, confirmada el 12 de
 * noviembre de 2026; docs/dominio/modelo-de-datos.md, "CatalogValidation"). Antes había una por campus (D2).
 *
 * Cómo se funden las que ya existen (CV-S11, supuesto por confirmar), por periodo y departamento:
 * - Gana el estado menos avanzado: se queda la pendiente, si no la en revisión, si no una validada; a igual estado, la de
 *   id menor (la más antigua). Así nada queda validado si no lo estaba en todos los campus. Conserva su estado y, solo si
 *   queda validada, quién y cuándo la validó; si no, se quedan vacíos.
 * - Las observaciones y las marcas de revisado de las demás pasan a la que queda; una marca del mismo registro que ya
 *   estaba en la que queda no se repite.
 * - La foto es la unión de las fotos de todas (sin repetir registros), porque ahora la revisión cubre los salones de
 *   todos los campus; las notas se juntan, una por renglón, empezando por las de la que queda.
 * - Las demás se borran: una revisión no es catálogo. Su bitácora se queda como está, y la de la que queda registra la
 *   fusión (evento `merged`): de cada fundida su id, su campus, su estado y quién y cuándo la validó.
 * - La que queda pierde su campus, y el CHECK `catalog_reviews_campus_check` ahora exige que ninguna revisión lo tenga.
 *
 * down() no tiene reversa útil: regresa el CHECK de antes solo si ninguna revisión de salones quedó sin campus, y si
 * alguna quedó, lanza una excepción, porque no hay de dónde sacar el campus.
 */
return new class extends Migration
{
    /** El menos avanzado primero: gana la fusión. */
    private const array RANK = ['pending' => 0, 'in_review' => 1, 'validated' => 2];

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
        $absorbed = $reviews->slice(1)->values();
        $others = $absorbed->pluck('id')->all();
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

        $validated = $kept->status === 'validated';

        DB::table('catalog_reviews')->where('id', $kept->id)->update([
            'campus_id' => null,
            'snapshot' => $snapshot === null ? null : json_encode($snapshot),
            'notes' => $notes === '' ? null : $notes,
            'validated_by' => $validated ? $kept->validated_by : null,
            'validated_at' => $validated ? $kept->validated_at : null,
        ]);

        if ($absorbed->isNotEmpty()) {
            $campuses = DB::table('campuses')->whereIn('id', $reviews->pluck('campus_id')->filter()->all())->pluck('code', 'id');
            $describe = fn (object $review): array => [
                'id' => $review->id,
                'campus' => $campuses[$review->campus_id] ?? null,
                'status' => $review->status,
                'validated_by' => $review->validated_by,
                'validated_at' => $review->validated_at,
            ];

            activity()
                ->performedOn(CatalogReview::query()->findOrFail($kept->id))
                ->event('merged')
                ->withProperties(['kept' => $describe($kept), 'absorbed' => $absorbed->map($describe)->all()])
                ->log('Fundió las revisiones de salones de cada campus en una para todos los campus (CV-S11)');
        }
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
