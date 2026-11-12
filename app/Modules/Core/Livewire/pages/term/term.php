<?php

use App\Modules\Core\Actions\CopyTermCalendar;
use App\Modules\Core\Actions\MakeTermCurrent;
use App\Modules\Core\Actions\OpenTerm;
use App\Modules\Core\Actions\SaveStageWindows;
use App\Modules\Core\Actions\UpdateTerm;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Livewire\Forms\TermForm;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ModalityQuery;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Core\Rules\TermRules;
use App\Modules\Core\Support\StageTimeline;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Ficha del periodo (tarea 2.1, maqueta B «Línea de tiempo»): su encabezado con «Editar» y «Marcar como actual», el
 * resumen de modalidades en un renglón (dónde se abrió y «Abrir en…» para las modalidades que faltan; M4: el periodo
 * se abre y se calendariza por modalidad; cada «Abrir en» abre el panel «Abrir el periodo» con lo que se crea) y el calendario de sus 8 etapas como una línea de tiempo
 * (core::terms.timeline) con la barra general de cada etapa y una barra delgada por cada modalidad con ventana propia.
 * Tocar una etapa abre su panel lateral (x-drawer): las fechas generales y, por cada modalidad activa, una ventana
 * propia opcional que se agrega y se quita. La sección «Modalidades» lista las activas, y las que se abrieron y ya no
 * están activas, solo lectura: el panel (`prepareOpen`) abre el periodo en una modalidad o completa una apertura con OpenTerm
 * (`openIn`, `completeIn`) y se dice en un toast lo que creó.
 *
 * Lo ven y cambian el admin y la subdirección (TermPolicy). El componente lee con TermQuery, TermCalendarQuery y
 * ModalityQuery, autoriza en mount() y en cada método público (una petición puede llegar con una página que abrió otra
 * sesión), valida la forma de los datos y llama a UpdateTerm, MakeTermCurrent, SaveStageWindows, que
 * hacen cumplir las reglas (TermRules). Las fechas de las etapas solo informan (D4).
 */
new #[Title('Ficha del periodo')] class extends Component
{
    use Toast;

    /** El periodo de la ficha. El navegador no lo puede cambiar. */
    #[Locked]
    public string $termId = '';

    public bool $showModal = false;

    public TermForm $form;

    /** El modal «Copiar de otro periodo». */
    public bool $showCopy = false;

    /** El periodo del que se copia el calendario; viene del navegador, así que se vuelve a buscar y a validar. */
    public string $sourceId = '';

    /**
     * La vista previa de la copia del periodo elegido; se calcula al elegirlo, no en cada petición.
     *
     * @var array{weeks: int, direction: string, groups: list<array{stage: string, rows: list<array{who: string, general: bool, dates: string}>}>, skipped: list<string>}|null
     */
    #[Locked]
    public ?array $copyData = null;

    /**
     * Lo que copiar reemplazaría, calculado al abrir el modal: el texto del aviso, o null si no hay ventanas.
     *
     * @var array{text: string}|null
     */
    #[Locked]
    public ?array $copyReplace = null;

    /** El panel «Abrir el periodo»; `showOpen` no puede ser #[Locked]: el x-drawer lo cierra desde el navegador. */
    public bool $showOpen = false;

    /** La modalidad del panel «Abrir el periodo». El navegador no la puede cambiar; se vuelve a leer y autorizar al abrir. */
    #[Locked]
    public ?string $openingId = null;

    /** `showDrawer` no puede ser #[Locked]: el x-drawer lo cierra desde el navegador. */
    public bool $showDrawer = false;

    /** La etapa que se edita en el panel (valor de Stage). El navegador no la puede cambiar. */
    #[Locked]
    public ?string $stage = null;

    /** Las fechas generales de la etapa en edición; vacías si no tiene ventana general. */
    public array $general = ['starts_on' => '', 'ends_on' => ''];

    /**
     * Las ventanas propias de la etapa en edición, por id de modalidad activa; solo las modalidades que la tienen.
     *
     * @var array<string, array{starts_on: string, ends_on: string}>
     */
    public array $own = [];

    public function mount(string $term, TermQuery $terms): void
    {
        $record = $terms->findOrFail($term);
        $this->authorize('view', $record);

        $this->termId = $record->id;
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TermQuery $terms, TermCalendarQuery $calendar, ModalityQuery $modalities): array
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('view', $term);

        $active = $modalities->active();
        $general = $calendar->for($term, null);
        $stage = $this->stage === null ? null : Stage::from($this->stage);
        $modalityRows = $terms->modalityRows($term, $active);
        $copySources = $calendar->copySources($term);
        $opening = $this->showOpen && $this->openingId !== null ? $modalities->findOrFail($this->openingId) : null;

        return [
            'term' => $term,
            'timeline' => StageTimeline::build($general, $calendar->ownWindows($term, $active), today()->toImmutable()),
            'modalities' => $active,
            'modalityRows' => $modalityRows,
            'openedRows' => $modalityRows->filter(fn (array $row): bool => $row['opening'] !== null)->values(),
            'pendingRows' => $modalityRows->filter(fn (array $row): bool => $row['opening'] === null && $row['actionable'])->values(),
            'canOpen' => Gate::allows('open', $term),
            'openModality' => $opening,
            'openPreview' => $opening === null ? null : $terms->openingPreview($term, $opening),
            'editing' => $stage === null ? null : [
                'stage' => $stage,
                'number' => array_search($stage, Stage::cases(), true) + 1,
                'window' => collect($general)->firstWhere('stage', $stage),
                'generalPreview' => $this->generalPreview(),
                'generalEmpty' => trim((string) ($this->general['starts_on'] ?? '')) === '' && trim((string) ($this->general['ends_on'] ?? '')) === '',
            ],
            'copySources' => $copySources,
            'copy' => $this->copyData,
            'copyReplace' => $this->copyReplace,
            'canUpdate' => Gate::allows('update', $term),
            'canMakeCurrent' => Gate::allows('makeCurrent', $term),
            'canManageCalendar' => Gate::allows('manageCalendar', $term),
        ];
    }

    /**
     * Abre el modal para copiar el calendario de otro periodo.
     */
    public function openCopy(TermQuery $terms, TermCalendarQuery $calendar, ModalityQuery $modalities): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $this->resetValidation();
        $this->sourceId = '';
        $this->copyData = null;
        $this->copyReplace = $this->replaceNotice($term, $calendar, $modalities);
        $this->showCopy = true;
    }

    /**
     * Calcula la vista previa del periodo elegido (una sola vez por cambio, no en cada render). Un id que no está
     * entre los periodos con calendario no tiene vista previa; `copyCalendar` lo rechaza.
     */
    public function updatedSourceId(TermQuery $terms, TermCalendarQuery $calendar): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $this->resetValidation();

        $source = $calendar->copySources($term)->firstWhere('id', $this->sourceId);
        $this->copyData = $source === null ? null : $this->copyView($calendar->copyPreview($term, $source));
    }

    /**
     * Copia el calendario del periodo elegido con CopyTermCalendar (reemplaza las ventanas actuales) y dice en un
     * toast cuántas ventanas copió. Un periodo desconocido o sin calendario se rechaza como error del campo.
     */
    public function copyCalendar(TermQuery $terms, CopyTermCalendar $copyTermCalendar): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        try {
            $source = $terms->findOrFail($this->sourceId);
        } catch (ModelNotFoundException) {
            $this->addError('sourceId', 'Elige un periodo de la lista.');

            return;
        }

        try {
            $written = $copyTermCalendar->handle($term, $source);
        } catch (ValidationException $e) {
            $this->addError('sourceId', collect($e->errors())->flatten()->implode(' '));

            return;
        }

        $this->showCopy = false;
        $this->sourceId = '';
        $this->copyData = null;
        $this->copyReplace = null;
        $this->success('Calendario copiado', e($written === 1 ? "1 ventana copiada de {$source->code}" : "{$written} ventanas copiadas de {$source->code}"));
    }

    /**
     * Sugiere el nombre del periodo con el código, si el usuario no escribió uno propio.
     */
    public function updatedFormCode(string $value, TermQuery $terms, TermRules $rules): void
    {
        $this->authorize('update', $terms->findOrFail($this->termId));

        $this->form->suggestName($value, $rules);
    }

    public function edit(TermQuery $terms, TermRules $rules): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('update', $term);

        $this->resetValidation();
        $this->form->fillFrom($term, $rules);
        $this->showModal = true;
    }

    public function save(UpdateTerm $updateTerm, TermQuery $terms): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('update', $term);

        $term = $this->form->submit(fn (array $data): Term => $updateTerm->handle($term, $data));

        $this->showModal = false;
        $this->success('Periodo actualizado', $this->describe($term));
    }

    public function makeCurrent(TermQuery $terms, MakeTermCurrent $makeTermCurrent): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('makeCurrent', $term);

        $term = $makeTermCurrent->handle($term);

        $this->success('Periodo actual', $this->describe($term));
    }

    /**
     * Abre el panel «Abrir el periodo» de una modalidad activa (U4 opción A): lo que se creará, sin escribir nada. En una
     * modalidad ya abierta es «Completar apertura». Confirmar es `openIn` o `completeIn`.
     */
    public function prepareOpen(string $modalityId, TermQuery $terms, ModalityQuery $modalities): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('open', $term);

        $modality = $this->activeModality($modalities, $modalityId);
        $this->authorize('open', [$term, $modality]);

        $this->openingId = $modality->id;
        $this->showOpen = true;
    }

    /**
     * Abre el periodo en una modalidad (la primera vez) y dice en un toast lo que se creó.
     */
    public function openIn(string $modalityId, TermQuery $terms, ModalityQuery $modalities, OpenTerm $openTerm): void
    {
        $this->openTerm($modalityId, 'Periodo abierto en', $terms, $modalities, $openTerm);
    }

    /**
     * Completa la apertura de una modalidad ya abierta: crea solo lo que falte.
     */
    public function completeIn(string $modalityId, TermQuery $terms, ModalityQuery $modalities, OpenTerm $openTerm): void
    {
        $this->openTerm($modalityId, 'Apertura completada en', $terms, $modalities, $openTerm);
    }

    /**
     * Abre el panel de una etapa con las ventanas que tiene guardadas: la general y las de las modalidades activas.
     */
    public function editStage(string $stage, TermQuery $terms, TermCalendarQuery $calendar, ModalityQuery $modalities): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $stage = Stage::tryFrom($stage) ?? abort(404);
        $active = $modalities->active()->pluck('id')->all();
        $stored = $calendar->storedWindows($term, $stage);
        $general = $stored->firstWhere('modality_id', null);

        $this->resetValidation();
        $this->stage = $stage->value;
        $this->general = [
            'starts_on' => $general?->starts_on->toDateString() ?? '',
            'ends_on' => $general?->ends_on->toDateString() ?? '',
        ];
        $this->own = $stored
            ->filter(fn ($window): bool => $window->modality_id !== null && in_array($window->modality_id, $active, true))
            ->mapWithKeys(fn ($window): array => [$window->modality_id => [
                'starts_on' => $window->starts_on->toDateString(),
                'ends_on' => $window->ends_on->toDateString(),
            ]])
            ->all();
        $this->showDrawer = true;
    }

    /**
     * Vacía las fechas generales; se quitan al guardar.
     */
    public function clearGeneral(TermQuery $terms): void
    {
        $this->authorize('manageCalendar', $terms->findOrFail($this->termId));

        $this->resetValidation();
        $this->general = ['starts_on' => '', 'ends_on' => ''];
    }

    /**
     * Agrega al panel la ventana propia de una modalidad, que empieza con las fechas generales; se guarda al guardar.
     */
    public function addModalityWindow(string $modalityId, TermQuery $terms, ModalityQuery $modalities): void
    {
        $this->authorize('manageCalendar', $terms->findOrFail($this->termId));

        $modality = $this->activeModality($modalities, $modalityId);

        $this->own[$modality->id] ??= [
            'starts_on' => $this->general['starts_on'],
            'ends_on' => $this->general['ends_on'],
        ];
    }

    /**
     * Quita del panel la ventana propia de una modalidad; se borra al guardar.
     */
    public function removeModalityWindow(string $modalityId, TermQuery $terms, ModalityQuery $modalities): void
    {
        $this->authorize('manageCalendar', $terms->findOrFail($this->termId));

        $modality = $this->activeModality($modalities, $modalityId);

        $this->resetValidation();
        unset($this->own[$modality->id]);
    }

    /**
     * Guarda las ventanas de la etapa con SaveStageWindows, todas o ninguna. Aquí solo se valida la forma de lo que
     * manda el navegador (puede venir manipulado) y se arman los datos: la general en blanco se quita, y se quitan
     * las ventanas propias de las modalidades activas que el panel ya no trae. Las fechas las revisa la Action.
     */
    public function saveStage(TermQuery $terms, TermCalendarQuery $calendar, ModalityQuery $modalities, SaveStageWindows $saveStageWindows): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $stage = Stage::tryFrom((string) $this->stage) ?? abort(404);
        $active = $modalities->active()->pluck('id')->all();
        $this->own = array_intersect_key($this->own, array_flip($active));

        $date = ['nullable', 'string', 'date_format:Y-m-d'];

        $this->validate([
            'general.starts_on' => $date,
            'general.ends_on' => $date,
            'own' => ['array'],
            'own.*' => ['array'],
            'own.*.starts_on' => $date,
            'own.*.ends_on' => $date,
        ]);

        $general = $this->normalized($this->general);
        $own = array_map($this->normalized(...), $this->own);

        foreach ($calendar->storedWindows($term, $stage) as $window) {
            if ($window->modality_id !== null && in_array($window->modality_id, $active, true) && ! array_key_exists($window->modality_id, $own)) {
                $own[$window->modality_id] = null;
            }
        }

        try {
            $saveStageWindows->handle($term, $stage, $general['starts_on'] === '' && $general['ends_on'] === '' ? null : $general, $own);
        } catch (ValidationException $e) {
            foreach ($e->errors() as $field => $messages) {
                foreach ($messages as $message) {
                    $this->addError($field, $message);
                }
            }

            return;
        }

        $this->showDrawer = false;
        $this->success('Etapa actualizada', e($stage->label()));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'general.starts_on' => 'inicio',
            'general.ends_on' => 'fin',
            'own.*.starts_on' => 'inicio',
            'own.*.ends_on' => 'fin',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'general.starts_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
            'general.ends_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
            'own.*.starts_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
            'own.*.ends_on.date_format' => 'El campo :attribute debe ser una fecha válida.',
            'own.*.array' => 'Las fechas de la modalidad no son válidas.',
            'own.array' => 'Las fechas de las modalidades no son válidas.',
        ];
    }

    /**
     * El aviso de lo que copiar reemplazaría: cuántas ventanas tiene el periodo y cuántas son de modalidades inactivas
     * (que no se ven en la línea de tiempo pero la copia también quita). Null si no tiene ninguna.
     *
     * @return array{text: string}|null
     */
    private function replaceNotice(Term $term, TermCalendarQuery $calendar, ModalityQuery $modalities): ?array
    {
        $count = $calendar->windowCount($term);

        if ($count === 0) {
            return null;
        }

        $active = $modalities->active()->pluck('id')->all();
        $inactive = collect(Stage::cases())
            ->flatMap(fn (Stage $stage) => $calendar->storedWindows($term, $stage))
            ->filter(fn ($window): bool => $window->modality_id !== null && ! in_array($window->modality_id, $active, true))
            ->count();

        $text = ($count === 1 ? 'Se reemplazará la ventana actual del periodo' : "Se reemplazarán las {$count} ventanas actuales del periodo")
            .match (true) {
                $inactive === 0 => '.',
                $inactive === 1 && $count === 1 => ', que es de una modalidad inactiva.',
                $inactive === 1 => ', 1 de ellas de una modalidad inactiva.',
                default => ", {$inactive} de ellas de modalidades inactivas.",
            };

        return ['text' => $text];
    }

    /**
     * La vista previa de la copia para el modal: las ventanas agrupadas por etapa con sus fechas, el corrimiento en
     * semanas y lo que no se copia.
     *
     * @param  array{shiftDays: int, windows: list<array{stage: Stage, modality: ?Modality, starts_on: CarbonImmutable, ends_on: CarbonImmutable}>, skipped: list<array{stage: Stage, modality: Modality}>}  $preview
     * @return array{weeks: int, direction: string, groups: list<array{stage: string, rows: list<array{who: string, general: bool, dates: string}>}>, skipped: list<string>}
     */
    private function copyView(array $preview): array
    {
        $groups = [];

        foreach ($preview['windows'] as $window) {
            $groups[$window['stage']->value] ??= ['stage' => $window['stage']->label(), 'rows' => []];
            $groups[$window['stage']->value]['rows'][] = [
                'who' => $window['modality']?->code ?? 'General',
                'general' => $window['modality'] === null,
                'dates' => StageTimeline::rangeBetween($window['starts_on'], $window['ends_on']),
            ];
        }

        return [
            'weeks' => intdiv(abs($preview['shiftDays']), 7),
            'direction' => $preview['shiftDays'] < 0 ? 'atrás' : 'adelante',
            'groups' => array_values($groups),
            'skipped' => array_map(fn (array $row): string => "{$row['modality']->code} · {$row['stage']->label()} (modalidad ".($row['modality']->trashed() ? 'borrada' : 'inactiva').')', $preview['skipped']),
        ];
    }

    /**
     * Las fechas generales que se están editando en el panel como «10–19 nov», para las modalidades que usan la general;
     * null si están vacías o no forman una ventana válida todavía.
     */
    private function generalPreview(): ?string
    {
        try {
            [$start, $end] = app(TermRules::class)->dates($this->general['starts_on'] ?? null, $this->general['ends_on'] ?? null);
        } catch (ValidationException) {
            return null;
        }

        return StageTimeline::rangeBetween(CarbonImmutable::parse($start), CarbonImmutable::parse($end));
    }

    /**
     * Una ventana del panel con sus dos fechas como texto; lo que falte o sea nulo queda vacío.
     *
     * @param  array<string, mixed>  $window
     * @return array{starts_on: string, ends_on: string}
     */
    private function normalized(array $window): array
    {
        return [
            'starts_on' => (string) ($window['starts_on'] ?? ''),
            'ends_on' => (string) ($window['ends_on'] ?? ''),
        ];
    }

    private function openTerm(string $modalityId, string $title, TermQuery $terms, ModalityQuery $modalities, OpenTerm $openTerm): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('open', $term);

        $modality = $modalities->findOrFail($modalityId);
        $this->authorize('open', [$term, $modality]);

        try {
            $lines = $openTerm->handle($term, $modality);
        } catch (ValidationException $e) {
            $this->error('No se abrió el periodo', e(collect($e->errors())->flatten()->implode(' ')));

            return;
        }

        $this->showOpen = false;
        $this->success(e("{$title} {$modality->name}"), e($lines === [] ? 'No faltaba nada.' : implode(', ', $lines)));
    }

    private function activeModality(ModalityQuery $modalities, string $id): Modality
    {
        $modality = $modalities->findOrFail($id);

        return $modality->is_active ? $modality : abort(404);
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Term $term): string
    {
        return e("{$term->code} · {$term->name}");
    }
};
