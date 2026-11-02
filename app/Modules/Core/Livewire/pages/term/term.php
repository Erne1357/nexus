<?php

use App\Modules\Core\Actions\MakeTermCurrent;
use App\Modules\Core\Actions\RemoveTermStage;
use App\Modules\Core\Actions\SetTermStage;
use App\Modules\Core\Actions\UpdateTerm;
use App\Modules\Core\Enums\Stage;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\TermStage;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\TermCalendarQuery;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Core\Rules\TermRules;
use App\Modules\Core\Support\StageTimeline;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Ficha del periodo (tarea 2.1, maqueta B «Línea de tiempo»): su encabezado con «Editar» y «Marcar como actual», y el
 * calendario de sus 8 etapas como una línea de tiempo (core::terms.timeline) con la barra general de cada etapa y una
 * barra delgada por cada campus con ventana propia. Tocar una etapa abre su panel lateral (x-drawer): las fechas
 * generales y, por cada campus activo, una ventana propia opcional que se agrega y se quita. La sección «Campus»
 * (abrir el periodo por campus) es de la tarea 2.2.
 *
 * Lo ven y cambian el admin y la subdirección (TermPolicy). El componente lee con TermQuery, TermCalendarQuery y
 * CampusQuery, autoriza en mount() y en cada método público (una petición puede llegar con una página que abrió otra
 * sesión), valida la forma de los datos y llama a UpdateTerm, MakeTermCurrent, SetTermStage y RemoveTermStage, que
 * hacen cumplir las reglas (TermRules). Las fechas de las etapas solo informan (D4).
 */
new #[Title('Ficha del periodo')] class extends Component
{
    use Toast;

    /** El periodo de la ficha. El navegador no lo puede cambiar. */
    #[Locked]
    public string $termId = '';

    public bool $showModal = false;

    /** El último nombre que sugirió el código: mientras el nombre sea ese (o esté vacío) se vuelve a sugerir. */
    #[Locked]
    public string $suggestedName = '';

    public string $code = '';

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    /** `showDrawer` no puede ser #[Locked]: el x-drawer lo cierra desde el navegador. */
    public bool $showDrawer = false;

    /** La etapa que se edita en el panel (valor de Stage). El navegador no la puede cambiar. */
    #[Locked]
    public ?string $stage = null;

    /** Las fechas generales de la etapa en edición; vacías si no tiene ventana general. */
    public array $general = ['starts_on' => '', 'ends_on' => ''];

    /**
     * Las ventanas propias de la etapa en edición, por id de campus activo; solo los campus que la tienen.
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
    public function with(TermQuery $terms, TermCalendarQuery $calendar, CampusQuery $campuses): array
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('view', $term);

        $active = $campuses->active();
        $general = $calendar->for($term, null);
        $stage = $this->stage === null ? null : Stage::from($this->stage);

        return [
            'term' => $term,
            'timeline' => StageTimeline::build($general, $calendar->ownWindows($term, $active), today()->toImmutable()),
            'campuses' => $active,
            'editing' => $stage === null ? null : [
                'stage' => $stage,
                'number' => array_search($stage, Stage::cases(), true) + 1,
                'window' => collect($general)->firstWhere('stage', $stage),
            ],
            'canUpdate' => Gate::allows('update', $term),
            'canMakeCurrent' => Gate::allows('makeCurrent', $term),
            'canManageCalendar' => Gate::allows('manageCalendar', $term),
        ];
    }

    /**
     * Sugiere el nombre del periodo con el código, si el usuario no escribió uno propio.
     */
    public function updatedCode(string $value, TermQuery $terms, TermRules $rules): void
    {
        $this->authorize('update', $terms->findOrFail($this->termId));

        $suggestion = $rules->suggestedName($value);

        if ($suggestion !== null && ($this->name === '' || $this->name === $this->suggestedName)) {
            $this->name = $suggestion;
        }

        $this->suggestedName = $suggestion ?? $this->suggestedName;
    }

    public function edit(TermQuery $terms, TermRules $rules): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('update', $term);

        $this->resetValidation();
        $this->code = $term->code;
        $this->name = $term->name;
        $this->starts_on = $term->starts_on->toDateString();
        $this->ends_on = $term->ends_on->toDateString();
        $this->suggestedName = $rules->suggestedName($term->code) ?? '';
        $this->showModal = true;
    }

    public function save(UpdateTerm $updateTerm, TermQuery $terms): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('update', $term);

        $term = $updateTerm->handle($term, $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
        ]));

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
     * Abre el panel de una etapa con las ventanas que tiene guardadas: la general y las de los campus activos.
     */
    public function editStage(string $stage, TermQuery $terms, TermCalendarQuery $calendar, CampusQuery $campuses): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $stage = Stage::tryFrom($stage) ?? abort(404);
        $active = $campuses->active()->pluck('id')->all();
        $stored = $calendar->storedWindows($term, $stage);
        $general = $stored->firstWhere('campus_id', null);

        $this->resetValidation();
        $this->stage = $stage->value;
        $this->general = [
            'starts_on' => $general?->starts_on->toDateString() ?? '',
            'ends_on' => $general?->ends_on->toDateString() ?? '',
        ];
        $this->own = $stored
            ->filter(fn ($window): bool => $window->campus_id !== null && in_array($window->campus_id, $active, true))
            ->mapWithKeys(fn ($window): array => [$window->campus_id => [
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
     * Agrega al panel la ventana propia de un campus, que empieza con las fechas generales; se guarda al guardar.
     */
    public function addCampusWindow(string $campusId, TermQuery $terms, CampusQuery $campuses): void
    {
        $this->authorize('manageCalendar', $terms->findOrFail($this->termId));

        $campus = $this->activeCampus($campuses, $campusId);

        $this->own[$campus->id] ??= [
            'starts_on' => $this->general['starts_on'],
            'ends_on' => $this->general['ends_on'],
        ];
    }

    /**
     * Quita del panel la ventana propia de un campus; se borra al guardar.
     */
    public function removeCampusWindow(string $campusId, TermQuery $terms, CampusQuery $campuses): void
    {
        $this->authorize('manageCalendar', $terms->findOrFail($this->termId));

        $campus = $this->activeCampus($campuses, $campusId);

        $this->resetValidation();
        unset($this->own[$campus->id]);
    }

    /**
     * Guarda las ventanas de la etapa: pone las que cambiaron y quita las que el panel quitó. Primero revisa las
     * fechas de todas, para no guardar a medias.
     */
    public function saveStage(TermQuery $terms, TermCalendarQuery $calendar, CampusQuery $campuses, TermRules $rules, SetTermStage $setStage, RemoveTermStage $removeStage): void
    {
        $term = $terms->findOrFail($this->termId);
        $this->authorize('manageCalendar', $term);

        $stage = Stage::tryFrom((string) $this->stage) ?? abort(404);
        $active = $campuses->active()->keyBy('id');
        $this->own = array_intersect_key($this->own, $active->all());

        $this->validate([
            'general.starts_on' => ['nullable', 'date'],
            'general.ends_on' => ['nullable', 'date'],
            'own.*.starts_on' => ['nullable', 'date'],
            'own.*.ends_on' => ['nullable', 'date'],
        ]);

        $stored = $calendar->storedWindows($term, $stage);

        /** @var list<array{window: ?TermStage, campus: ?Campus, path: string, dates: array{0: string, 1: string}|null}> $plan */
        $plan = [];
        $failed = false;

        $wanted = [['campus' => null, 'path' => 'general', 'values' => $this->general]];

        foreach ($this->own as $campusId => $values) {
            $wanted[] = ['campus' => $active[$campusId], 'path' => "own.{$campusId}", 'values' => $values];
        }

        foreach ($wanted as $entry) {
            $window = $stored->first(fn ($candidate): bool => $candidate->campus_id === $entry['campus']?->id);
            $blank = array_map(fn (string $value): bool => $value === '', $entry['values']);

            if ($entry['campus'] === null && $blank['starts_on'] && $blank['ends_on']) {
                $plan[] = ['window' => $window, 'campus' => null, 'path' => $entry['path'], 'dates' => null];

                continue;
            }

            foreach (['starts_on', 'ends_on'] as $field) {
                if ($blank[$field]) {
                    $this->addError("{$entry['path']}.{$field}", 'Escribe la fecha.');
                    $failed = true;
                }
            }

            if ($blank['starts_on'] || $blank['ends_on']) {
                continue;
            }

            try {
                $plan[] = ['window' => $window, 'campus' => $entry['campus'], 'path' => $entry['path'], 'dates' => $rules->dates($entry['values']['starts_on'], $entry['values']['ends_on'])];
            } catch (ValidationException $e) {
                $this->addErrorsUnder($entry['path'], $e);
                $failed = true;
            }
        }

        if ($failed) {
            return;
        }

        $removed = $stored->filter(fn ($window): bool => $window->campus_id !== null
            && $active->has($window->campus_id)
            && ! array_key_exists($window->campus_id, $this->own));

        $path = 'general';

        try {
            foreach ($plan as $item) {
                $path = $item['path'];

                if ($item['dates'] === null) {
                    if ($item['window'] !== null) {
                        $removeStage->handle($item['window']);
                    }

                    continue;
                }

                $unchanged = $item['window'] !== null
                    && $item['window']->starts_on->toDateString() === $item['dates'][0]
                    && $item['window']->ends_on->toDateString() === $item['dates'][1];

                if (! $unchanged) {
                    $setStage->handle($term, $stage, $item['campus'], ...$item['dates']);
                }
            }

            foreach ($removed as $window) {
                $removeStage->handle($window);
            }
        } catch (ValidationException $e) {
            $this->addErrorsUnder($path, $e);

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
            'code' => 'código',
            'name' => 'nombre',
            'starts_on' => 'inicio',
            'ends_on' => 'fin',
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
            'starts_on.date' => 'El campo :attribute debe ser una fecha válida.',
            'ends_on.date' => 'El campo :attribute debe ser una fecha válida.',
            'general.starts_on.date' => 'El campo :attribute debe ser una fecha válida.',
            'general.ends_on.date' => 'El campo :attribute debe ser una fecha válida.',
            'own.*.starts_on.date' => 'El campo :attribute debe ser una fecha válida.',
            'own.*.ends_on.date' => 'El campo :attribute debe ser una fecha válida.',
        ];
    }

    /**
     * Pasa a cada campo del panel los errores de una Action; el error del campus cae en su fecha de inicio.
     */
    private function addErrorsUnder(string $path, ValidationException $e): void
    {
        foreach ($e->errors() as $field => $messages) {
            $field = $field === 'campus_id' ? 'starts_on' : $field;

            foreach ($messages as $message) {
                $this->addError("{$path}.{$field}", $message);
            }
        }
    }

    private function activeCampus(CampusQuery $campuses, string $id): Campus
    {
        $campus = $campuses->findOrFail($id);

        return $campus->is_active ? $campus : abort(404);
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Term $term): string
    {
        return e("{$term->code} · {$term->name}");
    }
};
