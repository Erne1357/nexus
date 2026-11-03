<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\RoleSummaryQuery;
use App\Modules\Core\Queries\ScopedContext;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * Selector de perfil, campus y periodo activos, en la tarjeta de la credencial de la banda. Quien tiene más de un
 * puesto ve «Actuando como», un renglón por puesto con su alcance (RoleSummaryQuery, armado una vez al montar: no
 * cambia mientras la página está abierta); elegir uno lo cambia con switchRole() (SwitchRole) y regresa a Inicio.
 * Con un solo puesto no hay selector (la credencial ya dice cuál es). Ofrece los campus de
 * ScopedContext::campuses() (fijo si solo hay uno) y todos los periodos, y apply() guarda la elección con
 * SetActiveContext, que rechaza un campus ajeno; apply() nunca cambia el perfil. Elegir el periodo actual deja el
 * de omisión, para seguirlo cuando cambie. Sin periodo activo, el selector muestra "Sin periodo". Después lo
 * confirma con un toast y recarga la página para que todo lo que depende del contexto lo use. Si el campus o el
 * periodo no existen o no se permiten, avisa con un toast de error y regresa la selección al contexto vigente.
 */
new class extends Component
{
    use Toast;

    public ?string $roleValue = null;

    public ?string $campusId = null;

    public ?string $termId = null;

    /** @var list<array{id: string, name: string}> un renglón por puesto, con dos o más; si no, ninguno */
    #[Locked]
    public array $roles = [];

    public function mount(ScopedContext $context, RoleSummaryQuery $summaries): void
    {
        $this->roleValue = $context->activeRole()?->value;
        $this->campusId = $context->activeCampus()?->getKey();
        $this->termId = $context->activeTerm()?->getKey();

        $rows = count($context->heldRoles()) > 1 ? $summaries->forHeldRoles($context) : [];
        $this->roles = array_map(fn (string $value, string $summary): array => ['id' => $value, 'name' => $summary], array_keys($rows), $rows);
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

    /**
     * Cambia al perfil elegido en «Actuando como» y regresa a Inicio. Si no lo tiene, avisa y deja el de antes.
     */
    public function switchRole(SwitchRole $switchRole, ScopedContext $context): void
    {
        $role = Role::tryFrom((string) $this->roleValue);

        if ($role === null || $role === $context->activeRole()) {
            $this->roleValue = $context->activeRole()?->value;

            return;
        }

        try {
            $switchRole->handle($role);
        } catch (AuthorizationException $exception) {
            $this->roleValue = $context->activeRole()?->value;
            $this->error('No se cambió el perfil', e($exception->getMessage()));

            return;
        }

        $this->redirect(route('home'));
    }

    /**
     * Guarda el campus y el periodo elegidos. No toca el perfil: eso es de switchRole().
     */
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
        $this->roleValue = $context->activeRole()?->value;
        $this->campusId = $context->activeCampus()?->getKey();
        $this->termId = $context->activeTerm()?->getKey();

        $this->error('No se cambió el campus ni el periodo', e($reason));
    }
};
