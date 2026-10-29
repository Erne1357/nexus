<?php

namespace App\Modules\Imports\Templates;

use App\Modules\Imports\Models\ImportBatch;
use DateTimeInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\Validator;
use LogicException;

/**
 * Contrato de una plantilla de importación: qué campos espera de una exportación del SII, cómo
 * normaliza y valida cada fila y cómo la escribe. Hay una por catálogo y todas viven en
 * `Imports/Templates/` (Imports depende de los catálogos, no al revés); se registran en
 * ImportsServiceProvider. El motor lee el archivo, mapea sus columnas a los campos de la plantilla
 * y por cada fila llama a normalize(), validate() y, si no hubo errores, apply().
 *
 * Una plantilla no guarda estado: el registro la crea de nuevo en cada uso y debe servir igual en
 * cualquier lote. Lo que cambia por lote llega en ImportContext.
 *
 * Reglas de datos que el contrato ya cumple (docs/dominio/reglas-de-negocio.md, "Reglas de datos"):
 *
 * - Alta o cambio por clave natural: aplicar dos veces el mismo archivo no duplica ni cambia nada.
 * - Importar nunca reactiva ni restaura: un registro inactivo se actualiza pero sigue inactivo
 *   (advertencia), y uno borrado con la misma clave es error.
 * - Importar nunca desactiva por sí solo: lo que no viene en el archivo se desactiva únicamente si el
 *   admin elige "desactivar faltantes" para ese lote (missing()).
 * - apply() no deja bitácora por registro: el lote deja una entrada al aplicarse, y el detalle por
 *   registro queda en `import_rows`.
 * - Un cambio no pisa con null lo que el archivo trae vacío o no trae: borrar un dato es una edición
 *   a mano.
 *
 * apply() escribe sin pasar por las Actions del catálogo porque corre dentro de la Action que aplica el
 * lote (transacción, bitácora del lote y su evento). Si el catálogo tiene reglas propias, como
 * CampusRules, la plantilla las usa en normalize() y check() para que un registro importado cumpla lo
 * mismo que uno capturado.
 *
 * Ejemplo para copiar: tests/Fixtures/ImportTemplates/CampusesTemplate.php.
 */
abstract class ImportTemplate
{
    private ?bool $softDeletes = null;

    /**
     * Clave de la plantilla: el valor de `import_batches.template` (`careers`, `rooms`, `kardex`…).
     */
    abstract public function key(): string;

    /**
     * Nombre de la plantilla en la interfaz.
     */
    abstract public function label(): string;

    /**
     * Campos que espera, en el orden del archivo, con su etiqueta en español en minúsculas: las
     * columnas de la exportación se mapean a estos campos y la etiqueta nombra el campo en los
     * mensajes de error ("El campo clave es obligatorio.").
     *
     * @return array<string, string>
     */
    abstract public function fields(): array;

    /**
     * Campos que identifican el registro (clave natural de reglas-de-negocio.md, "Reglas de datos").
     * Si la clave es por campus, no se repite aquí: requiresCampus() agrega `campus_id`.
     *
     * @return list<string>
     */
    abstract public function naturalKey(): array;

    /**
     * Reglas de Laravel por campo sobre la fila ya normalizada. Las que leen la base (la materia
     * existe, el plan existe) van en check().
     *
     * @return array<string, mixed>
     */
    abstract public function rules(): array;

    /**
     * Modelo que escribe apply().
     *
     * @return class-string<Model>
     */
    abstract public function model(): string;

    /**
     * Si el lote pertenece a un campus. La clave natural incluye entonces `campus_id` (por ejemplo,
     * `rooms.code` es única por campus) y el contexto debe traer el campus.
     */
    public function requiresCampus(): bool
    {
        return false;
    }

    /**
     * Normaliza una fila ya mapeada: deja solo los campos de fields(), en su orden, con null en los
     * que faltan; recorta el texto, junta los espacios repetidos y convierte el texto vacío en null.
     * Las fechas de un Excel pasan a texto ISO (`2027-01-15`, o con hora si la trae) porque la fila
     * normalizada se guarda en JSON y apply() la lee de ahí: lo validado y lo aplicado tienen que ser
     * iguales. Los números pasan igual. Una plantilla la extiende para lo propio de su catálogo, como
     * poner las claves en mayúsculas, llamando primero a la de aquí.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $normalized = [];

        foreach (array_keys($this->fields()) as $field) {
            $value = $row[$field] ?? null;

            if ($value instanceof DateTimeInterface) {
                $value = $value->format($value->format('H:i:s') === '00:00:00' ? 'Y-m-d' : 'Y-m-d H:i:s');
            }

            if (is_string($value)) {
                // Con UTF-8 inválido preg_replace da null: el valor sigue tal cual y validate() lo reporta.
                $value = trim(preg_replace('/\s+/u', ' ', $value) ?? $value);
                $value = $value === '' ? null : $value;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * Valida una fila normalizada: primero que el texto sea UTF-8 válido, luego rules(), con los
     * mensajes en español y la etiqueta de cada campo; si pasan, check() la revisa contra la base.
     *
     * @param  array<string, mixed>  $row
     */
    final public function validate(array $row, ImportContext $context): RowIssues
    {
        $encoding = new RowIssues;

        foreach ($row as $field => $value) {
            if (is_string($value) && ! mb_check_encoding($value, 'UTF-8')) {
                $encoding = $encoding->withError($field, 'El texto no es UTF-8 válido: guarda el archivo en UTF-8 o vuelve a exportarlo.');
            }
        }

        if ($encoding->hasErrors()) {
            return $encoding;
        }

        $validator = Validator::make($row, $this->rules(), $this->messages(), $this->fields());

        if ($validator->fails()) {
            return new RowIssues(errors: $validator->errors()->toArray());
        }

        return $this->check($row, $context, new RowIssues);
    }

    /**
     * Valores de la clave natural de una fila normalizada, más `campus_id` si la plantilla es de un
     * campus. Es lo que busca apply() y con lo que el motor detecta filas repetidas en un archivo.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     *
     * @throws LogicException si la plantilla requiere campus y el contexto no lo trae
     */
    final public function keyFor(array $row, ImportContext $context): array
    {
        $key = [];

        foreach ($this->naturalKey() as $field) {
            $key[$field] = $row[$field] ?? null;
        }

        if (! $this->requiresCampus()) {
            return $key;
        }

        return [...$key, 'campus_id' => $this->campusKey($context)];
    }

    /**
     * Escribe una fila válida y devuelve el registro: alta si nadie tiene su clave natural, cambio si
     * ya existe. La clave (con `campus_id`) se fija aunque el modelo no la deje asignar en masa. El
     * alta suma defaults(); el cambio solo escribe lo que da attributes() sin los null y nunca cambia
     * `is_active`, así que no reactiva. El motor lee `wasRecentlyCreated` y `wasChanged()` para
     * contar altas y cambios del lote.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws LogicException si un registro borrado tiene la clave: validate() ya lo reporta como error
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $key = $this->keyFor($row, $context);
        $model = $this->query()->where($key)->first() ?? (new ($this->model()))->forceFill($key);

        if ($this->isTrashed($model)) {
            throw new LogicException("La plantilla {$this->key()} no restaura el registro borrado {$model->getKey()}.");
        }

        $attributes = $this->attributes($row, $context);

        if ($model->exists) {
            $attributes = array_filter($attributes, fn (mixed $value): bool => $value !== null);
            unset($attributes['is_active']);
        } else {
            $attributes = [...$this->defaults($context), ...$attributes];
        }

        $model->fill($attributes)->save();

        return $model;
    }

    /**
     * Si el catálogo ofrece "desactivar faltantes" al aplicar un lote (tarea 1.3). Por omisión, si su
     * modelo convierte `is_active` en casts(), como todo catálogo; el kardex, el historial docente y las
     * evaluaciones no lo tienen y no lo ofrecen. Una plantilla cuyo archivo no trae el catálogo completo
     * lo apaga.
     */
    public function deactivatesMissing(): bool
    {
        return (new ($this->model()))->hasCast('is_active');
    }

    /**
     * @throws LogicException si la plantilla no desactiva faltantes
     */
    final public function ensureDeactivatesMissing(): void
    {
        if (! $this->deactivatesMissing()) {
            throw new LogicException("La plantilla {$this->key()} no desactiva faltantes.");
        }
    }

    /**
     * Los registros que "desactivar faltantes" podría tocar: los activos y sin borrar del catálogo, y
     * solo los del campus del lote si la plantilla es de un campus. Contra ellos se cuentan los que no
     * vienen en el archivo (missing()).
     *
     * @return Builder<Model>
     *
     * @throws LogicException si la plantilla no desactiva faltantes, o si requiere campus y el contexto
     *                        no lo trae
     */
    final public function deactivationScope(ImportContext $context): Builder
    {
        $this->ensureDeactivatesMissing();

        $query = $this->model()::query();
        $model = $query->getModel();
        $query->where($model->qualifyColumn('is_active'), true);

        if ($this->requiresCampus()) {
            $query->where($model->qualifyColumn('campus_id'), $this->campusKey($context));
        }

        return $query;
    }

    /**
     * Los registros que "desactivar faltantes" desactivaría con este lote: los de deactivationScope()
     * que ninguna fila del archivo menciona (reglas-de-negocio.md, "Reglas de datos", confirmado el 29
     * de octubre de 2026). Una fila ya aplicada protege el registro que escribió (`target`). Cualquier
     * otra, también la que trae error, protege el registro con su clave natural, porque igual dice que
     * sigue en el SII; una fila sin clave no protege a ninguno.
     *
     * La clave se compara como texto: la columna, tal como la guarda la base, contra el valor de la fila
     * normalizada, que guarda los campos con el nombre de la columna que escribe apply(). Las claves
     * naturales de Nexus son texto; una columna que convierte la clave (un número) solo queda protegida
     * por su fila aplicada.
     *
     * ImportBatchQuery la usa para mostrar cuáles serían y ApplyImportBatch para desactivarlos.
     *
     * @return Builder<Model>
     *
     * @throws LogicException como deactivationScope()
     */
    final public function missing(ImportBatch $batch, ImportContext $context): Builder
    {
        $query = $this->deactivationScope($context);
        $model = $query->getModel();
        $grammar = $query->getQuery()->getGrammar();

        return $query
            ->whereNotExists(fn (QueryBuilder $rows) => $rows->selectRaw('1')
                ->from('import_rows')
                ->where('import_rows.batch_id', $batch->id)
                ->where('import_rows.target_type', $model->getMorphClass())
                ->whereColumn('import_rows.target_id', $model->getQualifiedKeyName()))
            ->whereNotExists(function (QueryBuilder $rows) use ($batch, $model, $grammar): void {
                $rows->selectRaw('1')->from('import_rows')->where('import_rows.batch_id', $batch->id);

                foreach ($this->naturalKey() as $field) {
                    $rows->whereRaw("import_rows.normalized ->> ?::text = {$grammar->wrap($model->qualifyColumn($field))}::text", [$field]);
                }
            });
    }

    /**
     * Corre una vez después de aplicar el lote completo, por ejemplo para despachar un job de
     * recálculo (tarea 1.4: `RecomputeStudentProgress`).
     */
    public function afterApply(ImportContext $context): void {}

    /**
     * Mensajes propios de rules(); los demás salen de lang/es/validation.php.
     *
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [];
    }

    /**
     * Revisa contra la base una fila que ya pasó rules(). Por omisión, solo el registro que tiene su
     * clave natural: si está borrado es error y si está inactivo, advertencia. Una plantilla que
     * revisa claves cruzadas la extiende y llama primero a esta.
     *
     * @param  array<string, mixed>  $row
     */
    protected function check(array $row, ImportContext $context, RowIssues $issues): RowIssues
    {
        $existing = $this->query()->where($this->keyFor($row, $context))->first();

        if ($existing === null) {
            return $issues;
        }

        $field = $this->naturalKey()[0];

        if ($this->isTrashed($existing)) {
            return $issues->withError($field, 'Hay un registro borrado con esta clave; una importación no lo restaura.');
        }

        if ($existing->getAttribute('is_active') === false) {
            return $issues->withWarning($field, 'El registro está inactivo: se actualizan sus datos, pero sigue inactivo.');
        }

        return $issues;
    }

    /**
     * Lo que apply() escribe de una fila válida, en altas y cambios. Por omisión, la fila tal cual; la
     * clave natural y `campus_id` ya los pone la búsqueda por clave.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    protected function attributes(array $row, ImportContext $context): array
    {
        return $row;
    }

    /**
     * Lo que solo se escribe al dar de alta y nunca pisa un cambio (por ejemplo, un perfil por
     * omisión que después se edita a mano).
     *
     * @return array<string, mixed>
     */
    protected function defaults(ImportContext $context): array
    {
        return [];
    }

    /**
     * El campus del lote, para una plantilla de un campus.
     *
     * @throws LogicException si el contexto no lo trae
     */
    private function campusKey(ImportContext $context): mixed
    {
        if ($context->campus === null) {
            throw new LogicException("La plantilla {$this->key()} necesita un campus en el contexto del lote.");
        }

        return $context->campus->getKey();
    }

    /**
     * Consulta del modelo con los borrados incluidos: un borrado sigue ocupando su clave natural
     * (la clave es única también entre borrados, skill nexus-module).
     *
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $query = $this->model()::query();

        return $this->softDeletes() ? $query->withTrashed() : $query;
    }

    private function isTrashed(Model $model): bool
    {
        return $model->exists && $this->softDeletes() && $model->trashed();
    }

    /**
     * Si el modelo usa SoftDeletes. Se calcula una vez por instancia: depende solo de model().
     */
    private function softDeletes(): bool
    {
        return $this->softDeletes ??= in_array(SoftDeletes::class, class_uses_recursive($this->model()), true);
    }
}
