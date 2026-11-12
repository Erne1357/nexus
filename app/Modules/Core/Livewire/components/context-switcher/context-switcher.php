<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\RoleSummaryQuery;
use App\Modules\Core\Queries\TermQuery;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * Selector de perfil y periodo activos, en la tarjeta de la credencial de la banda. Quien tiene más de un puesto ve
 * «Actuando como», un renglón por puesto con su alcance (RoleSummaryQuery, armado una vez al montar: no cambia mientras
 * la página está abierta); elegir uno lo cambia con switchRole() (SwitchRole) y regresa a Inicio. Con un solo puesto no
 * hay selector (la credencial ya dice cuál es). La credencial ya no elige campus (#415): lo elige cada pantalla en su
 * URL. Ofrece todos los periodos, y apply() guarda la elección con SetActiveContext; apply() nunca cambia el perfil.
 * Elegir el periodo actual deja el de omisión, para seguirlo cuando cambie. Sin periodo activo, el selector muestra
 * "Sin periodo". Después lo confirma con un toast y recarga la página, con el campus que la página tenía elegido
 * (`?campus=`), para que todo lo que depende del periodo lo use. Si el periodo no existe, avisa con un toast de error
 * y regresa la selección al periodo vigente.
 */
new class extends Component
{
    use Toast;

    public ?string $roleValue = null;

    public ?string $termId = null;

    /** @var list<array{id: string, name: string}> un renglón por puesto, con dos o más; si no, ninguno */
    #[Locked]
    public array $roles = [];

    public function mount(Actor $context, RoleSummaryQuery $summaries): void
    {
        $this->roleValue = $context->activeRole()?->value;
        $this->termId = $context->activeTerm()?->getKey();

        $rows = count($context->heldRoles()) > 1 ? $summaries->forHeldRoles($context) : [];
        $this->roles = array_map(fn (string $value, string $summary): array => ['id' => $value, 'name' => $summary], array_keys($rows), $rows);
    }

    /**
     * @return array{terms: Collection<int, Term>}
     */
    public function with(Actor $context, TermQuery $terms): array
    {
        return [
            'terms' => $context->user() === null ? new Collection : $terms->selectable(),
        ];
    }

    /**
     * Cambia al perfil elegido en «Actuando como» y regresa a Inicio. Si no lo tiene, avisa y deja el de antes.
     */
    public function switchRole(SwitchRole $switchRole, Actor $context): void
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
     * Guarda el periodo elegido. No toca el perfil: eso es de switchRole().
     */
    public function apply(SetActiveContext $setActiveContext, Actor $context, TermQuery $terms): void
    {
        try {
            $term = blank($this->termId) ? null : $terms->findOrFail($this->termId);

            $setActiveContext->handle($term?->is_current ? null : $term);
        } catch (ModelNotFoundException) {
            $this->reject('El periodo elegido ya no existe.', $context);

            return;
        } catch (AuthorizationException $exception) {
            $this->reject($exception->getMessage(), $context);

            return;
        }

        // El toast de MaryUI pinta título y descripción con x-html: el nombre va escapado.
        $this->success('Periodo actualizado', e((string) $context->activeTerm()?->name), redirectTo: $this->pageUrl());
    }

    /**
     * La página donde está la credencial, para recargarla: su ruta y, si la tiene, el campus que eligió (`?campus=`,
     * que en una petición de Livewire viene en Referer). Lo demás de su consulta no, porque puede ser del otro periodo.
     */
    private function pageUrl(): string
    {
        parse_str((string) parse_url((string) request()->headers->get('referer'), PHP_URL_QUERY), $query);
        $campus = $query['campus'] ?? null;

        return Livewire::originalUrl().(is_string($campus) && $campus !== '' ? '?'.http_build_query(['campus' => $campus]) : '');
    }

    /**
     * Regresa la selección al contexto vigente y avisa por qué no cambió.
     */
    private function reject(string $reason, Actor $context): void
    {
        $this->roleValue = $context->activeRole()?->value;
        $this->termId = $context->activeTerm()?->getKey();

        $this->error('No se cambió el periodo', e($reason));
    }
};
