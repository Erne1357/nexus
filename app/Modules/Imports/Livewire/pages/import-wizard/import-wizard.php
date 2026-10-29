<?php

use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Imports\Actions\CreateImportMapping;
use App\Modules\Imports\Actions\StoreImportBatch;
use App\Modules\Imports\Enums\BatchStatus;
use App\Modules\Imports\Enums\RowIssue;
use App\Modules\Imports\Jobs\ApplyQueuedBatch;
use App\Modules\Imports\Jobs\ValidateQueuedBatch;
use App\Modules\Imports\Models\ImportBatch;
use App\Modules\Imports\Models\ImportMapping;
use App\Modules\Imports\Queries\ImportBatchQuery;
use App\Modules\Imports\Queries\ImportMappingQuery;
use App\Modules\Imports\Readers\SpreadsheetReader;
use App\Modules\Imports\Readers\UnreadableFile;
use App\Modules\Imports\Templates\ColumnMatcher;
use App\Modules\Imports\Templates\ImportContext;
use App\Modules\Imports\Templates\ImportTemplate;
use App\Modules\Imports\Templates\TemplateRegistry;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\Features\SupportFileUploads\FileUploadConfiguration;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Livewire\WithFileUploads;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Asistente de importación (tarea 1.2), en cuatro pasos: archivo, columnas, validación y aplicación.
 *
 * 1. El admin elige la plantilla y el archivo. El asistente lee el encabezado (SpreadsheetReader); una
 *    plantilla de un campus usa el de la barra superior.
 * 2. Propone qué columna va a cada campo con ColumnMatcher, por nombre o con un mapeo guardado, y avisa
 *    las columnas de ese mapeo que el archivo no trae. Si el admin cambia la propuesta, la guarda como
 *    mapeo nuevo con CreateImportMapping (pide un nombre). Al confirmar sube el lote con
 *    StoreImportBatch y lo manda a validar a la cola (ValidateQueuedBatch). Si la cola no responde, el
 *    lote ya quedó abierto y "Validar de nuevo" lo vuelve a encolar: un job de más no lo toca.
 * 3. Muestra las cuentas de la validación y las filas con error o con advertencia, con sus mensajes, y
 *    ofrece el CSV de errores cuando la cola lo termina (ExportBatchErrors). Si el catálogo lo ofrece,
 *    dice cuántos de sus registros activos no vienen en el archivo y deja elegir "desactivar faltantes"
 *    (tarea 1.3); elegido, los lista, y el botón y la confirmación repiten la cifra. "Aplicar" manda el
 *    lote a la cola (ApplyQueuedBatch) si sigue validado y tiene filas válidas.
 * 4. Muestra el avance de la aplicación y, al terminar, lo que escribió, las filas que se saltaron y,
 *    si se eligió, cuántos registros desactivó y cuáles.
 *
 * Los pasos 3 y 4 leen el lote de la URL (`?lote=`), así que se retoman al volver a la página o desde
 * los lotes recientes. Mientras la cola trabaja, o prepara el archivo de errores, la vista se refresca
 * sola con `wire:poll`.
 *
 * La copia temporal del archivo (una exportación del SII, con datos personales) se borra al subir el
 * lote, al cambiar de archivo y al empezar de nuevo; si el admin cierra la pestaña, la borra la limpieza
 * de Livewire a las 24 horas.
 *
 * Todo es del admin (ImportBatchPolicy, fila "Importar catálogos"): mount() y cada acción vuelven a
 * autorizar, porque una petición puede llegar con una página que abrió otra sesión. El encabezado y el
 * lote los decide el servidor (`#[Locked]`); lo que llega del navegador (plantilla, columnas, mapeo) se
 * vuelve a validar contra el registro de plantillas y el encabezado leído.
 */
new #[Title('Importaciones')] class extends Component
{
    use Toast, WithFileUploads, WithPagination;

    /** Paso del indicador (MaryUI x-steps); lo recalcula cada render a partir del estado. */
    public int $step = 1;

    public string $template = '';

    /** @var TemporaryUploadedFile|null */
    public $file = null;

    /** @var list<string> columnas del archivo, leídas en continue() */
    #[Locked]
    public array $header = [];

    /** El archivo temporal cuyas columnas se leyeron: upload() sube ese y no otro. */
    #[Locked]
    public string $readFile = '';

    /** '' es "por nombre de columna"; si no, el id de un mapeo guardado de la plantilla. */
    public string $mappingId = '';

    /** @var array<string, string> campo de la plantilla → columna del archivo ('' sin columna) */
    public array $assignments = [];

    /** Nombre del mapeo nuevo, cuando el admin cambia la propuesta. */
    public string $mappingName = '';

    /** Si esta página ya mandó a aplicar el lote y espera a que la cola lo tome. */
    #[Locked]
    public bool $applyQueued = false;

    /** Si el admin eligió desactivar lo que no viene en el archivo al aplicar el lote. */
    public bool $deactivateMissing = false;

    /** Qué filas del lote se listan: un valor de RowIssue. */
    public string $issues = RowIssue::Errors->value;

    /** El lote que se valida o se aplica; en la URL para retomarlo. */
    #[Locked]
    #[Url(as: 'lote')]
    public ?string $batchId = null;

    public function mount(): void
    {
        $this->authorize('viewAny', ImportBatch::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ImportBatchQuery $batches, ImportMappingQuery $mappings, TemplateRegistry $templates, ScopedContext $context, ColumnMatcher $matcher): array
    {
        $batch = $this->batchId === null ? null : $batches->findOrFail($this->batchId);

        if ($batch !== null) {
            $this->authorize('view', $batch);
        }

        $definition = $templates->has($this->template) ? $templates->get($this->template) : null;
        $saved = $definition !== null && $this->header !== [] ? $mappings->forTemplate($definition->key()) : collect();
        $selected = $saved->firstWhere('id', $this->mappingId);

        $this->step = match (true) {
            $batch !== null => $this->stepFor($batch),
            $this->header !== [] => 2,
            default => 1,
        };

        $labels = array_map(fn (ImportTemplate $template): string => $template->label(), $templates->all());
        $batchDefinition = $batch !== null && $templates->has($batch->template) ? $templates->get($batch->template) : null;
        // Con la validación terminada hay filas que revisar; mientras la cola trabaja, solo el avance.
        $reviewing = $batch !== null && ! $batch->status->inProgress() && $batch->total_rows > 0;
        $applied = $reviewing && $this->step === 4 ? $batches->appliedCounts($batch) : null;
        $errorRows = $applied['skipped'] ?? $batch?->error_rows ?? 0;
        $errorsReady = $reviewing && $errorRows > 0 && $batches->errorsFileReady($batch);
        $errorsPending = $reviewing && $errorRows > 0 && ! $errorsReady;
        // La cola lo escribe en segundos; si no llega, no se espera para siempre.
        $errorsLost = $errorsPending && $batch->updated_at->lt(now()->subMinutes(10));
        $applyQueued = $this->applyQueued && $batch?->status === BatchStatus::Validated;
        $importContext = $batch === null ? null : new ImportContext($context->user(), $batch->campus);
        $offersDeactivation = $reviewing && $this->step === 3 && $batch->status === BatchStatus::Validated && $batchDefinition?->deactivatesMissing();
        $missing = $offersDeactivation ? $batches->missingSummary($batch, $batchDefinition, $importContext) : null;
        $deactivating = $this->deactivateMissing && ($missing['missing'] ?? 0) > 0;

        return [
            // La vista recibe las propiedades antes de with(): el paso de este render va aparte.
            'currentStep' => $this->step,
            'batch' => $batch,
            'batchTemplate' => $batch === null ? null : ($labels[$batch->template] ?? $batch->template),
            'batchFields' => $batchDefinition?->fields() ?? [],
            'batchKey' => $batchDefinition?->naturalKey() ?? [],
            'polling' => $batch !== null && ($batch->status->inProgress() || $applyQueued || ($errorsPending && ! $errorsLost)),
            'applyQueued' => $applyQueued,
            'errorsLost' => $errorsLost,
            'warningRows' => $reviewing && $this->step === 3 ? $batches->warningRows($batch) : 0,
            'applied' => $applied,
            'missing' => $missing,
            'deactivating' => $deactivating,
            'missingRows' => $deactivating ? $batches->missingRecords($batch, $batchDefinition, $importContext) : null,
            'deactivatedRows' => $applied !== null && $batch->deactivate_missing && $batchDefinition !== null ? $batches->deactivatedRecords($batch, $batchDefinition) : null,
            'errorRows' => $errorRows,
            'errorsReady' => $errorsReady,
            'issueKinds' => array_map(fn (RowIssue $kind): array => ['id' => $kind->value, 'name' => $kind->label()], RowIssue::cases()),
            'issueRows' => $reviewing ? $batches->issues($batch, RowIssue::tryFrom($this->issues) ?? RowIssue::Errors) : null,
            'definition' => $definition,
            'campus' => $context->activeCampus(),
            'templateLabels' => $labels,
            'templates' => array_map(fn (string $key, string $label): array => ['id' => $key, 'name' => $label], array_keys($labels), $labels),
            'mappings' => [
                ['id' => '', 'name' => 'Por nombre de columna'],
                ...$saved->map(fn (ImportMapping $mapping): array => ['id' => $mapping->id, 'name' => $mapping->name])->all(),
            ],
            'selectedMapping' => $selected,
            'absentColumns' => $selected === null ? [] : $matcher->absentColumns($this->header, $selected->columns),
            'columnOptions' => [
                ['id' => '', 'name' => '— Sin columna —'],
                ...array_map(fn (string $column): array => ['id' => $column, 'name' => $column], $this->header),
            ],
            'recent' => $this->step === 1 ? $batches->recent() : collect(),
        ];
    }

    /**
     * Paso 1 → 2: lee el encabezado del archivo y propone las columnas por nombre.
     */
    public function continue(TemplateRegistry $templates, SpreadsheetReader $reader, ScopedContext $context, ColumnMatcher $matcher): void
    {
        $this->authorize('create', ImportBatch::class);

        $this->validate($this->fileRules($templates));

        $definition = $templates->get($this->template);

        if ($definition->requiresCampus() && $context->activeCampus() === null) {
            $this->addError('template', 'Elige en la barra superior el campus al que pertenece el archivo.');

            return;
        }

        try {
            $header = $reader->header($this->file->getRealPath());
        } catch (UnreadableFile $exception) {
            $this->addError('file', $exception->getMessage());

            return;
        }

        $this->header = $header;
        $this->readFile = $this->file->getFilename();
        $this->reset('mappingId', 'mappingName');
        $this->assignments = $this->assignmentsFrom($definition, $matcher->match($definition, $header));
    }

    /**
     * Elegir un mapeo guardado (o volver a "por nombre") cambia la propuesta.
     */
    public function updatedMappingId(): void
    {
        $this->authorize('create', ImportBatch::class);

        $templates = app(TemplateRegistry::class);

        if ($this->header === [] || ! $templates->has($this->template)) {
            return;
        }

        $definition = $templates->get($this->template);
        $mapping = $this->selectedMapping(app(ImportMappingQuery::class));

        $this->resetValidation(['assignments', 'mappingName']);
        $this->assignments = $this->assignmentsFrom($definition, app(ColumnMatcher::class)->match($definition, $this->header, $mapping?->columns));
    }

    /**
     * Paso 2 → 3: sube el lote, con el mapeo elegido, sin mapeo (por nombre) o con uno nuevo si el admin
     * cambió la propuesta, y lo manda a validar a la cola.
     */
    public function upload(
        TemplateRegistry $templates,
        ImportMappingQuery $mappings,
        ColumnMatcher $matcher,
        CreateImportMapping $createMapping,
        StoreImportBatch $storeBatch,
        ScopedContext $context,
    ): void {
        $this->authorize('create', ImportBatch::class);

        $this->validate([
            ...$this->fileRules($templates),
            'assignments' => ['array'],
            'assignments.*' => ['nullable', 'string', Rule::in($this->header)],
            'mappingName' => ['nullable', 'string', 'max:255'],
        ]);

        if ($this->file->getFilename() !== $this->readFile) {
            $this->addError('file', 'El archivo cambió después de revisar sus columnas: vuelve al paso 1 y revísalas otra vez.');

            return;
        }

        $definition = $templates->get($this->template);
        $columns = $this->columnsFrom($definition);

        if ($columns === null) {
            return;
        }

        if (($missing = $matcher->describeMissingKey($definition, $columns)) !== null) {
            $this->addError('assignments', $missing);

            return;
        }

        $mapping = $this->selectedMapping($mappings);

        if ($this->getErrorBag()->has('mappingId')) {
            return;
        }

        if ($columns != $matcher->match($definition, $this->header, $mapping?->columns)) {
            if (trim($this->mappingName) === '') {
                $this->addError('mappingName', 'Ponle un nombre al mapeo para guardarlo: las columnas ya no son las de la propuesta.');

                return;
            }

            $mapping = $this->renamingErrors(['name' => 'mappingName', 'columns' => 'assignments'], fn (): ImportMapping => $createMapping->handle(
                $context->user(),
                $this->template,
                $this->mappingName,
                $columns,
            ));

            // Si subir el lote falla, el mapeo ya está guardado: el siguiente intento lo usa.
            $this->mappingId = $mapping->id;
            $this->mappingName = '';
        }

        $batch = $this->renamingErrors([], fn (): ImportBatch => $storeBatch->handle(
            $context->user(),
            $this->template,
            $this->file,
            $context->activeCampus(),
            $mapping,
        ));

        // El archivo ya vive en el disco `imports` con su lote. Desde aquí la página apunta al lote, para
        // que un fallo de la cola no lleve a subirlo dos veces.
        $this->discardUpload();
        $this->reset('header', 'readFile', 'assignments', 'mappingId', 'mappingName');
        $this->batchId = $batch->id;

        $this->queueValidation($batch);
    }

    /**
     * Vuelve a mandar a validar un lote que se quedó subido, por ejemplo porque la cola no respondió.
     * Un job de más no toca el lote: el primero que lo toma lo valida.
     */
    public function requeue(ImportBatchQuery $batches): void
    {
        $this->authorize('create', ImportBatch::class);

        $batch = $batches->findOrFail((string) $this->batchId);
        $this->authorize('view', $batch);

        if ($batch->status === BatchStatus::Uploaded) {
            $this->queueValidation($batch);
        }
    }

    /**
     * Cambiar de archivo borra la copia temporal del anterior.
     */
    public function updatingFile(): void
    {
        $this->discardUpload();
    }

    public function updatedIssues(): void
    {
        $this->resetPage();
    }

    public function updatedDeactivateMissing(): void
    {
        $this->resetPage('faltantes');
    }

    /**
     * Paso 3 → 4: manda a aplicar en la cola un lote validado con filas válidas. Las filas con error
     * se saltan (reglas-de-negocio.md, "Reglas de datos"). Desactiva faltantes solo si el admin lo eligió
     * y el catálogo lo ofrece, y nunca más de los que vio y confirmó: el botón manda esa cifra.
     *
     * @param  int|null  $confirmedMissing  cuántos faltantes mostraba la página al confirmar
     */
    public function apply(ImportBatchQuery $batches, ScopedContext $context, TemplateRegistry $templates, ?int $confirmedMissing = null): void
    {
        $this->authorize('viewAny', ImportBatch::class);

        $batch = $batches->findOrFail((string) $this->batchId);
        $this->authorize('apply', $batch);

        if ($batch->status !== BatchStatus::Validated) {
            $this->error('No se puede aplicar', e("El lote ya no está validado: está {$batch->status->label()}."));

            return;
        }

        if ($batch->valid_rows === 0) {
            $this->error('No se puede aplicar', 'El lote no tiene filas válidas que aplicar.');

            return;
        }

        $deactivates = $this->deactivateMissing
            && $confirmedMissing !== null
            && $templates->has($batch->template)
            && $templates->get($batch->template)->deactivatesMissing();

        try {
            ApplyQueuedBatch::dispatch($batch, $context->user(), $deactivates ? max(0, $confirmedMissing) : null);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('La cola no respondió', 'El lote sigue validado: intenta aplicarlo otra vez en un momento.');

            return;
        }

        $this->applyQueued = true;
    }

    /**
     * Paso 2 → 1: elegir otro archivo u otra plantilla.
     */
    public function back(): void
    {
        $this->reset('header', 'readFile', 'assignments', 'mappingId', 'mappingName');
        $this->resetValidation();
    }

    /**
     * Retoma un lote, desde los lotes recientes.
     */
    public function open(string $id, ImportBatchQuery $batches): void
    {
        $this->authorize('viewAny', ImportBatch::class);

        $batch = $batches->findOrFail($id);
        $this->authorize('view', $batch);

        $this->batchId = $batch->id;
        $this->reset('issues', 'applyQueued', 'deactivateMissing');
        $this->resetPage();
    }

    /**
     * Empieza otra importación desde el paso 1.
     */
    public function startOver(): void
    {
        $this->discardUpload();
        $this->reset('template', 'file', 'header', 'readFile', 'assignments', 'mappingId', 'mappingName', 'batchId', 'issues', 'applyQueued', 'deactivateMissing');
        $this->resetPage();
        $this->resetValidation();
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'template.required' => 'Elige una plantilla de importación.',
            'file.required' => 'Elige el archivo que exportaste del SII.',
            'file.file' => 'Elige el archivo que exportaste del SII.',
            'file.extensions' => 'El archivo debe ser .xlsx, .csv o .txt.',
            'assignments.*.in' => 'El archivo no tiene esa columna.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['mappingName' => 'nombre del mapeo'];
    }

    private function stepFor(ImportBatch $batch): int
    {
        return match ($batch->status) {
            BatchStatus::Uploaded, BatchStatus::Validating, BatchStatus::Validated => 3,
            BatchStatus::Applying, BatchStatus::Applied => 4,
            // Una validación fallida no deja filas; una aplicación fallida sí venía validada.
            BatchStatus::Failed => $batch->total_rows > 0 ? 4 : 3,
        };
    }

    /**
     * Plantilla y archivo, en continue() y otra vez en upload(): llegan del navegador.
     *
     * @return array<string, list<mixed>>
     */
    private function fileRules(TemplateRegistry $templates): array
    {
        return [
            'template' => ['required', 'string', function (string $attribute, mixed $value, Closure $fail) use ($templates): void {
                if (! $templates->has($value)) {
                    $fail('Elige una plantilla de importación.');
                }
            }],
            'file' => ['required', 'file', 'extensions:xlsx,csv,txt'],
        ];
    }

    private function queueValidation(ImportBatch $batch): void
    {
        try {
            ValidateQueuedBatch::dispatch($batch);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('La cola no respondió', 'El archivo quedó subido: usa «Validar de nuevo» en un momento.');
        }
    }

    /**
     * Borra la copia temporal del archivo subido y su .json, que guarda el nombre original.
     */
    private function discardUpload(): void
    {
        if ($this->file instanceof TemporaryUploadedFile) {
            $name = $this->file->getFilename();

            Storage::disk(FileUploadConfiguration::disk())->delete([
                FileUploadConfiguration::path($name, false),
                FileUploadConfiguration::path("{$name}.json", false),
            ]);
        }

        $this->file = null;
    }

    /**
     * El mapeo guardado que eligió el admin, solo si sigue activo y es de la plantilla; si no, vuelve a
     * "por nombre" con el error.
     */
    private function selectedMapping(ImportMappingQuery $mappings): ?ImportMapping
    {
        if ($this->mappingId === '') {
            return null;
        }

        try {
            return $mappings->findActiveFor($this->template, $this->mappingId);
        } catch (ModelNotFoundException) {
            $this->mappingId = '';
            $this->addError('mappingId', 'Ese mapeo ya no está disponible para esta plantilla.');

            return null;
        }
    }

    /**
     * Columna → campo, como lo guarda un mapeo, a partir de lo que eligió el admin por campo.
     *
     * @return array<string, string>|null null si una columna quedó en dos campos
     */
    private function columnsFrom(ImportTemplate $definition): ?array
    {
        $fields = $definition->fields();
        $columns = [];
        $fieldsByColumn = [];

        foreach ($fields as $field => $label) {
            $column = $this->assignments[$field] ?? '';

            if ($column !== '' && $column !== null) {
                $fieldsByColumn[$column][] = $label;
                $columns[$column] = $field;
            }
        }

        foreach ($fieldsByColumn as $column => $labels) {
            if (count($labels) > 1) {
                $last = array_pop($labels);
                $this->addError('assignments', "La columna {$column} está en dos campos: ".implode(', ', $labels)." y {$last}.");

                return null;
            }
        }

        return $columns;
    }

    /**
     * Campo → columna para los selects, a partir de columna → campo.
     *
     * @param  array<string, string>  $columns
     * @return array<string, string>
     */
    private function assignmentsFrom(ImportTemplate $definition, array $columns): array
    {
        $assignments = [];

        foreach (array_keys($definition->fields()) as $field) {
            $column = array_search($field, $columns, true);
            $assignments[$field] = $column === false ? '' : (string) $column;
        }

        return $assignments;
    }

    /**
     * Corre una escritura y muestra sus errores junto al control de esta página que corresponde.
     *
     * @template TResult
     *
     * @param  array<string, string>  $names  campo de la Action → propiedad de la página
     * @param  Closure(): TResult  $write
     * @return TResult
     */
    private function renamingErrors(array $names, Closure $write): mixed
    {
        try {
            return $write();
        } catch (ValidationException $exception) {
            $errors = [];

            foreach ($exception->errors() as $key => $messages) {
                $errors[$names[$key] ?? $key] = $messages;
            }

            throw ValidationException::withMessages($errors);
        }
    }
};
