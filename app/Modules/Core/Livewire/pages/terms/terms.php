<?php

use App\Modules\Core\Actions\CreateTerm;
use App\Modules\Core\Actions\MakeTermCurrent;
use App\Modules\Core\Actions\UpdateTerm;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Forms\TermForm;
use App\Modules\Core\Models\Term;
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

    public TermForm $form;

    public function mount(): void
    {
        $this->authorize('viewAny', Term::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(TermQuery $terms, Actor $context): array
    {
        return [
            'terms' => $terms->forTable($context, $this->search),
            'canCreate' => Gate::allows('create', Term::class),
            'headers' => [
                ['key' => 'code', 'label' => 'Código'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-28 min-[1200px]:min-w-44'],
                ['key' => 'dates', 'label' => 'Fechas', 'sortable' => false],
                ['key' => 'opened_in', 'label' => 'Abierto en', 'sortable' => false],
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
    public function updatedFormCode(string $value, TermQuery $terms, TermRules $rules): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Term::class);
        } else {
            $this->authorize('update', $terms->findOrFail($this->editingId));
        }

        $this->form->suggestName($value, $rules);
    }

    public function create(TermQuery $terms, TermRules $rules): void
    {
        $this->authorize('create', Term::class);

        $this->resetValidation();
        $this->reset('editingId');
        $this->form->prefillNext($terms->latestCode(), $rules);
        $this->showModal = true;
    }

    public function edit(string $id, TermQuery $terms, TermRules $rules): void
    {
        $term = $terms->findOrFail($id);
        $this->authorize('update', $term);

        $this->resetValidation();
        $this->editingId = $term->id;
        $this->form->fillFrom($term, $rules);
        $this->showModal = true;
    }

    public function save(CreateTerm $createTerm, UpdateTerm $updateTerm, TermQuery $terms): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Term::class);
            $term = $this->form->submit(fn (array $data): Term => $createTerm->handle($data));
            $this->success('Periodo creado', $this->describe($term));
        } else {
            $term = $terms->findOrFail($this->editingId);
            $this->authorize('update', $term);
            $term = $this->form->submit(fn (array $data): Term => $updateTerm->handle($term, $data));
            $this->success('Periodo actualizado', $this->describe($term));
        }

        $this->showModal = false;
        $this->reset('editingId');
        $this->form->reset();
    }

    public function makeCurrent(string $id, TermQuery $terms, MakeTermCurrent $makeTermCurrent): void
    {
        $term = $terms->findOrFail($id);
        $this->authorize('makeCurrent', $term);

        $term = $makeTermCurrent->handle($term);

        $this->success('Periodo actual', $this->describe($term));
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Term $term): string
    {
        return e("{$term->code} · {$term->name}");
    }
};
