<?php

use App\Modules\CatalogValidation\Models\CatalogReview;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Materias, planes de estudio y carreras los valida Servicios Escolares, una sola revisión por catálogo y periodo para
 * todo el Tec (CV15, confirmada el 20 de noviembre de 2026; docs/dominio/modelo-de-datos.md, "CatalogValidation"):
 * esas revisiones no tienen departamento. Docentes y salones siguen por departamento.
 *
 * - `department_id` acepta nulo, y el CHECK `catalog_reviews_owner_check` exige que lo sea justo en esos tres
 *   catálogos. La unicidad de siempre (periodo, campus, departamento y catálogo, con los nulos como un valor más) deja
 *   una sola de Servicios Escolares por catálogo y periodo.
 * - Las que ya había, una por departamento, se funden por periodo y catálogo con el patrón de CV-S11 (supuestos por
 *   confirmar). Cuál estado queda depende del periodo:
 *   - en un periodo que ya terminó, gana el más avanzado (validada, en revisión, pendiente), para que la primera
 *     validación de Servicios Escolares cuente sus altas y bajas contra esa foto (CV3);
 *   - en un periodo con alguna carga fuera de borrador, si en algún departamento estaba validada, queda validada, para
 *     no volver a detener esas cargas (HC-14); si no, gana la menos avanzada;
 *   - en los demás, gana la menos avanzada (pendiente, en revisión, validada), así que nada queda validado si no lo
 *     estaba en todos los departamentos.
 *   A igual estado, la de id menor. La que queda conserva quién y cuándo la validó solo si queda validada; las
 *   observaciones y las marcas de las demás pasan a ella (una marca del mismo registro no se repite); su foto es la
 *   unión de las fotos; sus notas se juntan, una por renglón; las demás se borran (una revisión no es catálogo) y la
 *   bitácora de la que queda registra cada fundida (evento `merged`: id, departamento, estado, quién y cuándo la validó)
 *   y la regla que decidió (`rule`: `ended`, `loads` o `least`).
 * - Las observaciones que pasan conservan a quien las puso (`flagged_by`, el jefe académico): al resolverlas, su aviso
 *   (CV10) ya no le llega, porque ya no observa esas revisiones (aceptado).
 *
 * mergeIntoSchoolServices() es idempotente: un periodo y catálogo con una sola revisión sin departamento no cambia.
 * down() no tiene reversa con datos: regresa el `not null` solo si ninguna revisión quedó sin departamento, y si alguna
 * quedó, lanza una excepción, porque no hay de dónde sacar el departamento (aceptado: antes de migrar se respalda la
 * base).
 */
return new class extends Migration
{
    /** Los catálogos que valida Servicios Escolares (Catalog::ownedBy(CatalogOwner::SchoolServices)). */
    private const array CATALOGS = ['subjects', 'study_plans', 'careers'];

    /** Qué tan avanzado está cada estado. */
    private const array RANK = ['pending' => 0, 'in_review' => 1, 'validated' => 2];

    public function up(): void
    {
        DB::statement('alter table catalog_reviews alter column department_id drop not null');

        $this->mergeIntoSchoolServices();

        DB::statement("alter table catalog_reviews add constraint catalog_reviews_owner_check check ((catalog in ('subjects', 'study_plans', 'careers')) = (department_id is null))");
    }

    public function down(): void
    {
        if (DB::table('catalog_reviews')->whereNull('department_id')->exists()) {
            throw new RuntimeException('Hay revisiones de Servicios Escolares sin departamento: no se puede regresar a una por departamento.');
        }

        DB::statement('alter table catalog_reviews drop constraint if exists catalog_reviews_owner_check');
        DB::statement('alter table catalog_reviews alter column department_id set not null');
    }

    /**
     * Funde, por periodo y catálogo de Servicios Escolares, las revisiones que todavía tienen departamento en una sin
     * departamento (CV15). Idempotente.
     */
    public function mergeIntoSchoolServices(): void
    {
        $groups = DB::table('catalog_reviews')
            ->whereIn('catalog', self::CATALOGS)
            ->whereNotNull('department_id')
            ->select('term_id', 'catalog')
            ->distinct()
            ->get();

        foreach ($groups as $group) {
            $this->merge($group->term_id, $group->catalog);
        }
    }

    private function merge(string $termId, string $catalog): void
    {
        $reviews = DB::table('catalog_reviews')
            ->where('catalog', $catalog)
            ->where('term_id', $termId)
            ->orderBy('id')
            ->get();
        $rule = $this->ruleFor($termId);
        $anyValidated = $reviews->contains(fn (object $review): bool => $review->status === 'validated');
        $reviews = $reviews
            ->sortBy(fn (object $review): int => match (true) {
                $rule === 'ended', $rule === 'loads' && $anyValidated => -(self::RANK[$review->status] ?? -1),
                default => self::RANK[$review->status] ?? 3,
            })
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
            'department_id' => null,
            'snapshot' => $snapshot === null ? null : json_encode($snapshot),
            'notes' => $notes === '' ? null : $notes,
            'validated_by' => $validated ? $kept->validated_by : null,
            'validated_at' => $validated ? $kept->validated_at : null,
        ]);

        if ($absorbed->isNotEmpty()) {
            $departments = DB::table('departments')->whereIn('id', $reviews->pluck('department_id')->filter()->all())->pluck('code', 'id');
            $describe = fn (object $review): array => [
                'id' => $review->id,
                'department' => $departments[$review->department_id] ?? null,
                'status' => $review->status,
                'validated_by' => $review->validated_by,
                'validated_at' => $review->validated_at,
            ];

            activity()
                ->performedOn(CatalogReview::query()->findOrFail($kept->id))
                ->event('merged')
                ->withProperties(['kept' => $describe($kept), 'absorbed' => $absorbed->map($describe)->all(), 'rule' => $rule])
                ->log('Fundió las revisiones de cada departamento en una de Servicios Escolares para todo el Tec (CV15)');
        }
    }

    /**
     * Qué regla funde las revisiones del periodo: `ended` si ya terminó, `loads` si alguna de sus cargas salió de
     * borrador, `least` si no.
     */
    private function ruleFor(string $termId): string
    {
        return match (true) {
            DB::table('terms')->where('id', $termId)->whereDate('ends_on', '<', today()->toDateString())->exists() => 'ended',
            DB::table('academic_loads')->where('term_id', $termId)->where('status', '!=', 'draft')->exists() => 'loads',
            default => 'least',
        };
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
