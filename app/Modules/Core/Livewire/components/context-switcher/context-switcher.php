<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * Selector de campus y periodo activos de la topbar. Ofrece los campus de ScopedContext::campuses()
 * (fijo si solo hay uno) y todos los periodos, y guarda la elección con SetActiveContext, que
 * rechaza un campus ajeno. Elegir el periodo actual deja el de omisión, para seguirlo cuando cambie.
 * Sin periodo activo, el selector muestra "Sin periodo". Después lo confirma con un toast y recarga la
 * página para que todo lo que depende del contexto lo use. Si el campus o el periodo no existen o no
 * se permiten, avisa con un toast de error y regresa la selección al contexto vigente.
 */
new class extends Component
{
    use Toast;

    public ?string $campusId = null;

    public ?string $termId = null;

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

    public function apply(SetActiveContext $setActiveContext, ScopedContext $context, CampusQuery $campuses, TermQuery $terms): void
    {
        try {
            $campus = blank($this->campusId) ? null : $campuses->findOrFail($this->campusId);
            $term = blank($this->termId) ? null : $terms->findOrFail($this->termId);

            $setActiveContext->handle($campus, $term?->is_current ? null : $term);
        } catch (ModelNotFoundException) {
            $this->reject('El campus o el periodo elegido ya no existe.', $context);

            return;
        } catch (AuthorizationException $exception) {
            $this->reject($exception->getMessage(), $context);

            return;
        }

        // El toast de MaryUI pinta título y descripción con x-html: los nombres van escapados.
        $this->success(
            'Contexto actualizado',
            e(implode(' · ', array_filter([$context->activeCampus()?->name, $context->activeTerm()?->name]))),
            redirectTo: Livewire::originalUrl(),
        );
    }

    /**
     * Regresa la selección al contexto vigente y avisa por qué no cambió.
     */
    private function reject(string $reason, ScopedContext $context): void
    {
        $this->campusId = $context->activeCampus()?->getKey();
        $this->termId = $context->activeTerm()?->getKey();

        $this->error('No se cambió el campus ni el periodo', e($reason));
    }
};
