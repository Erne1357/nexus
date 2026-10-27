<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * Selector de campus y periodo activos de la topbar. Ofrece los campus de ScopedContext::campuses()
 * (fijo si solo hay uno) y todos los periodos, y guarda la elección con SetActiveContext, que
 * rechaza un campus ajeno. Elegir el periodo actual deja el de omisión, para seguirlo cuando cambie.
 * Después lo confirma con un toast y recarga la página para que todo lo que depende del contexto lo
 * use.
 */
new class extends Component
{
    use Toast;

    public ?int $campusId = null;

    public ?int $termId = null;

    public function mount(ScopedContext $context): void
    {
        $this->campusId = $context->activeCampus()?->getKey();
        $this->termId = $context->activeTerm()?->getKey();
    }

    /**
     * @return array{campuses: Collection<int, Campus>, terms: Collection<int, Term>}
     */
    public function with(ScopedContext $context, TermQuery $terms): array
    {
        return [
            'campuses' => $context->campuses(),
            'terms' => $context->user() === null ? new Collection : $terms->selectable(),
        ];
    }

    public function apply(SetActiveContext $setActiveContext, ScopedContext $context): void
    {
        $campus = $this->campusId === null ? null : Campus::findOrFail($this->campusId);
        $term = $this->termId === null ? null : Term::findOrFail($this->termId);

        $setActiveContext->handle($campus, $term?->is_current ? null : $term);

        // El toast de MaryUI pinta título y descripción con x-html: los nombres van escapados.
        $this->success(
            'Contexto actualizado',
            e(implode(' · ', array_filter([$context->activeCampus()?->name, $context->activeTerm()?->name]))),
            redirectTo: Livewire::originalUrl(),
        );
    }
};
