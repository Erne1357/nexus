<?php

namespace App\Modules\Imports\Queries;

use App\Modules\Core\Queries\KeyLookup;
use App\Modules\Imports\Enums\RowIssue;
use App\Modules\Imports\Enums\RowStatus;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportDeactivation;
use App\Modules\Imports\Models\ImportRow;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletingScope;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\LazyCollection;

/**
 * Lecturas de lotes de importación para el asistente y el archivo de errores. Quién las ve lo decide
 * la página con ImportBatchPolicy, que solo deja al admin: por eso no filtran por alcance.
 */
final class ImportBatchQuery
{
    /**
     * Los lotes más recientes, para retomar uno desde el asistente.
     *
     * @return Collection<int, ImportBatch>
     */
    public function recent(int $limit = 10): Collection
    {
        return ImportBatch::query()
            // Con el campus aunque se haya retirado: el lote sigue diciendo de cuál era.
            ->with(['campus' => fn (BelongsTo $campus) => $campus->withTrashed(), 'uploader'])
            ->latest()
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    public function findOrFail(string $id): ImportBatch
    {
        return KeyLookup::findOrFail(ImportBatch::query(), $id);
    }

    /**
     * Las filas del lote con error, o las que se aplican con advertencia (también las ya aplicadas),
     * por número de fila.
     *
     * @return LengthAwarePaginator<int, ImportRow>
     */
    public function issues(ImportBatch $batch, RowIssue $kind, int $perPage = 25): LengthAwarePaginator
    {
        return ImportRow::query()
            ->select(['id', 'batch_id', 'row_number', 'normalized', 'errors', 'warnings', 'status'])
            ->where('batch_id', $batch->id)
            ->tap(fn (Builder $query) => match ($kind) {
                RowIssue::Errors => $query->where('status', RowStatus::Error),
                RowIssue::Warnings => $this->withWarnings($query),
            })
            ->orderBy('row_number')
            ->paginate($perPage);
    }

    /**
     * Cuántas filas se aplican con advertencia: `valid_rows` las cuenta como válidas.
     */
    public function warningRows(ImportBatch $batch): int
    {
        return $this->withWarnings(ImportRow::query()->where('batch_id', $batch->id))->count();
    }

    /**
     * Lo que la aplicación del lote lleva escrito (las cuentas que ApplyImportBatch suma en cada bloque),
     * las filas con error que no se aplican y lo que desactivó por no venir en el archivo. Lo usan la
     * bitácora, BatchApplied y la pantalla.
     *
     * @return array{created: int, updated: int, unchanged: int, skipped: int, deactivated: int}
     */
    public function appliedCounts(ImportBatch $batch): array
    {
        return [
            'created' => $batch->created_rows,
            'updated' => $batch->updated_rows,
            'unchanged' => $batch->unchanged_rows,
            'skipped' => ImportRow::query()->where('batch_id', $batch->id)->where('status', RowStatus::Error)->count(),
            'deactivated' => $batch->deactivated_rows,
        ];
    }

    /**
     * Lo que "desactivar faltantes" haría con el lote: cuántos registros activos tiene el catálogo (los
     * del campus del lote si es de un campus) y cuántos de ellos no vienen en el archivo. Solo para una
     * plantilla que lo ofrece (ImportTemplate::deactivatesMissing()).
     *
     * @return array{active: int, missing: int}
     */
    public function missingSummary(ImportBatch $batch, ImportTemplate $template, ImportContext $context): array
    {
        return [
            'active' => $template->deactivationScope($context)->count(),
            'missing' => $template->missing($batch, $context)->count(),
        ];
    }

    /**
     * Los registros que "desactivar faltantes" desactivaría con el lote, por clave natural.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function missingRecords(ImportBatch $batch, ImportTemplate $template, ImportContext $context, int $perPage = 10): LengthAwarePaginator
    {
        return $this->byNaturalKey($template->missing($batch, $context), $template)->paginate($perPage, pageName: 'faltantes');
    }

    /**
     * Los registros que el lote desactivó por no venir en el archivo, por clave natural; también si
     * después se borraron.
     *
     * @return LengthAwarePaginator<int, Model>
     */
    public function deactivatedRecords(ImportBatch $batch, ImportTemplate $template, int $perPage = 10): LengthAwarePaginator
    {
        $query = $template->model()::query()->withoutGlobalScope(SoftDeletingScope::class);
        $model = $query->getModel();

        $query->whereIn($model->getQualifiedKeyName(), ImportDeactivation::query()
            ->where('batch_id', $batch->id)
            ->where('target_type', $model->getMorphClass())
            ->select('target_id'));

        return $this->byNaturalKey($query, $template)->paginate($perPage, pageName: 'desactivados');
    }

    /**
     * Si la cola ya dejó el CSV de errores del lote (ExportBatchErrors) para su último estado: el de la
     * validación deja de valer en cuanto el lote se empieza a aplicar, hasta que la cola lo rehace.
     */
    public function errorsFileReady(ImportBatch $batch): bool
    {
        return $batch->errors_exported_at !== null && Storage::disk('imports')->exists($batch->errors_path);
    }

    /**
     * Las filas con error en el orden del archivo, de mil en mil, con la fila como vino y sus errores,
     * para ExportBatchErrors. Un kardex mal mapeado puede traer cientos de miles.
     *
     * @return LazyCollection<int, ImportRow>
     */
    public function errorRows(ImportBatch $batch): LazyCollection
    {
        return ImportRow::query()
            ->select(['id', 'batch_id', 'row_number', 'raw', 'errors'])
            ->where('batch_id', $batch->id)
            ->where('status', RowStatus::Error)
            ->lazyById(1000, 'row_number');
    }

    /**
     * @param  Builder<Model>  $query
     * @return Builder<Model>
     */
    private function byNaturalKey(Builder $query, ImportTemplate $template): Builder
    {
        foreach ($template->naturalKey() as $field) {
            $query->orderBy($query->getModel()->qualifyColumn($field));
        }

        return $query->orderBy($query->getModel()->getQualifiedKeyName());
    }

    /**
     * Las filas sin error que traen advertencia. Una advertencia vacía puede estar guardada como `{}`
     * (la validación) o como `[]` (el cast del modelo).
     *
     * @param  Builder<ImportRow>  $query
     * @return Builder<ImportRow>
     */
    private function withWarnings(Builder $query): Builder
    {
        return $query->where('status', '<>', RowStatus::Error)->whereRaw("warnings NOT IN ('{}'::jsonb, '[]'::jsonb)");
    }
}
