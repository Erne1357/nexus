<?php

use App\Modules\Core\Actions\CreateTerm;
use App\Modules\Core\Actions\MakeTermCurrent;
use App\Modules\Core\Actions\UpdateTerm;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermQuery;
use App\Modules\Core\Rules\TermRules;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Catálogo de periodos: tabla con búsqueda, alta y edición en un modal y «Marcar como actual» desde el
 * menú de la fila. No hay borrar ni desactivar: un periodo no se borra.
 *
 * El componente lee con TermQuery, autoriza con TermPolicy en mount() y en cada método público (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateTerm, UpdateTerm o MakeTermCurrent, que hacen cumplir las reglas del periodo (TermRules).
 */
new #[Title('Periodos')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por código o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    public bool $showModal = false;

    /** El periodo que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    /** El último nombre que sugirió el código: mientras el nombre sea ese (o esté vacío) se vuelve a sugerir. */
    #[Locked]
    public string $suggestedName = '';

    public string $code = '';

    public string $name = '';

    public string $starts_on = '';

    public string $ends_on = '';

    public function mount(): void
    {
        $this->authorize('viewAny', Term::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TermQuery $terms, ScopedContext $context): array
    {
        return [
            'terms' => $terms->forTable($context, $this->search),
            'canCreate' => Gate::allows('create', Term::class),
            'headers' => [
                ['key' => 'code', 'label' => 'Código'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'starts_on', 'label' => 'Inicio'],
                ['key' => 'ends_on', 'label' => 'Fin'],
                ['key' => 'is_current', 'label' => 'Estado'],
            ],
        ];
    }

    public function updated(string $property): void
    {
        if ($property === 'search') {
            $this->resetPage();
        }
    }

    /**
     * Sugiere el nombre del periodo con el código, si el usuario no escribió uno propio.
     */
    public function updatedCode(string $value, TermRules $rules): void
    {
        $suggestion = $rules->suggestedName($value);

        if ($suggestion !== null && ($this->name === '' || $this->name === $this->suggestedName)) {
            $this->name = $suggestion;
        }

        $this->suggestedName = $suggestion ?? $this->suggestedName;
    }

    public function create(): void
    {
        $this->authorize('create', Term::class);

        $this->resetValidation();
        $this->reset('editingId', 'suggestedName', 'code', 'name', 'starts_on', 'ends_on');
        $this->showModal = true;
    }

    public function edit(string $id, TermQuery $terms, TermRules $rules): void
    {
        $term = $terms->findOrFail($id);
        $this->authorize('update', $term);

        $this->resetValidation();
        $this->editingId = $term->id;
        $this->code = $term->code;
        $this->name = $term->name;
        $this->starts_on = $term->starts_on->toDateString();
        $this->ends_on = $term->ends_on->toDateString();
        $this->suggestedName = $rules->suggestedName($term->code) ?? '';
        $this->showModal = true;
    }

    public function save(CreateTerm $createTerm, UpdateTerm $updateTerm, TermQuery $terms): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Term::class);
            $term = $createTerm->handle($this->validatedTerm());
            $this->success('Periodo creado', $this->describe($term));
        } else {
            $term = $terms->findOrFail($this->editingId);
            $this->authorize('update', $term);
            $term = $updateTerm->handle($term, $this->validatedTerm());
            $this->success('Periodo actualizado', $this->describe($term));
        }

        $this->showModal = false;
    }

    public function makeCurrent(string $id, TermQuery $terms, MakeTermCurrent $makeTermCurrent): void
    {
        $term = $terms->findOrFail($id);
        $this->authorize('makeCurrent', $term);

        $term = $makeTermCurrent->handle($term);

        $this->success('Periodo actual', $this->describe($term));
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
        ];
    }

    /**
     * Solo la forma: el formato del código, su unicidad y el orden de las fechas los decide TermRules en
     * la Action.
     *
     * @return array{code: string, name: string, starts_on: string, ends_on: string}
     */
    private function validatedTerm(): array
    {
        /** @var array{code: string, name: string, starts_on: string, ends_on: string} */
        return $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['required', 'date'],
        ]);
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Term $term): string
    {
        return e("{$term->code} · {$term->name}");
    }
};
