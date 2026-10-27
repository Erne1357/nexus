<?php

namespace App\Modules\Imports\Templates;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
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
 * - apply() no deja bitácora por registro: el lote deja una entrada al aplicarse, y el detalle por
 *   registro queda en `import_rows`.
 *
 * Ejemplo para copiar: tests/Fixtures/ImportTemplates/CampusesTemplate.php.
 */
abstract class ImportTemplate
{
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
     * Lo que no es texto (números y fechas de un Excel) pasa igual. Una plantilla la extiende para lo
     * propio de su catálogo, como poner las claves en mayúsculas, llamando primero a la de aquí.
     *
     * @param  array<string, mixed>  $row
     * @return array<string, mixed>
     */
    public function normalize(array $row): array
    {
        $normalized = [];

        foreach (array_keys($this->fields()) as $field) {
            $value = $row[$field] ?? null;

            if (is_string($value)) {
                $value = trim(preg_replace('/\s+/u', ' ', $value));
                $value = $value === '' ? null : $value;
            }

            $normalized[$field] = $value;
        }

        return $normalized;
    }

    /**
     * Valida una fila normalizada: primero rules(), con los mensajes en español y la etiqueta de
     * cada campo; si pasan, check() la revisa contra la base.
     *
     * @param  array<string, mixed>  $row
     */
    final public function validate(array $row, ImportContext $context): RowIssues
    {
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

        if ($context->campus === null) {
            throw new LogicException("La plantilla {$this->key()} necesita un campus en el contexto del lote.");
        }

        return [...$key, 'campus_id' => $context->campus->getKey()];
    }

    /**
     * Escribe una fila válida y devuelve el registro: alta si nadie tiene su clave natural, cambio si
     * ya existe. El alta suma defaults(); el cambio solo escribe lo que da attributes() y nunca
     * cambia `is_active`, así que no reactiva. El motor lee `wasRecentlyCreated` y `wasChanged()`
     * para contar altas y cambios del lote.
     *
     * @param  array<string, mixed>  $row
     *
     * @throws LogicException si un registro borrado tiene la clave: validate() ya lo reporta como error
     */
    public function apply(array $row, ImportContext $context): Model
    {
        $model = $this->query()->firstOrNew($this->keyFor($row, $context));

        if ($this->isTrashed($model)) {
            throw new LogicException("La plantilla {$this->key()} no restaura el registro borrado {$model->getKey()}.");
        }

        $attributes = $this->attributes($row, $context);

        if ($model->exists) {
            unset($attributes['is_active']);
        } else {
            $attributes = [...$this->defaults($context), ...$attributes];
        }

        $model->fill($attributes)->save();

        return $model;
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
     * Consulta del modelo con los borrados incluidos: un borrado sigue ocupando su clave natural.
     *
     * @return Builder<Model>
     */
    private function query(): Builder
    {
        $model = $this->model();
        $query = $model::query();

        return in_array(SoftDeletes::class, class_uses_recursive($model), true) ? $query->withTrashed() : $query;
    }

    private function isTrashed(Model $model): bool
    {
        return $model->exists && in_array(SoftDeletes::class, class_uses_recursive($model), true) && $model->trashed();
    }
}
