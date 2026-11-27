<?php

use App\Modules\Core\Actions\SetActiveContext;
use App\Modules\Core\Actions\SwitchRole;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\TermSlot;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Queries\RoleSummaryQuery;
use App\Modules\Core\Queries\TermQuery;
use Carbon\CarbonImmutable;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Collection;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\Livewire;
use Mary\Traits\Toast;

/**
 * Selector de perfil y periodos activos, en la tarjeta de la credencial de la banda. Quien tiene más de un puesto ve
 * «Actuando como», un renglón por puesto con su alcance (RoleSummaryQuery, armado una vez al montar: no cambia mientras
 * la página está abierta); elegir uno lo cambia con switchRole() (SwitchRole) y regresa a Inicio. Con un solo puesto no
 * hay selector (la credencial ya dice cuál es). La credencial ya no elige campus (#415): lo elige cada pantalla en su
 * URL. Lleva dos periodos (TP-04): «Periodo», de consulta, para todos (por omisión el que está en curso, TP-03), y
 * «Carga», solo para quien trabaja la carga (Actor::worksLoad(); por omisión el vigente que marca el admin). Ofrecen
 * todos los periodos, y applyView() y applyLoad() guardan la elección con SetActiveContext, cada uno en su ranura; nunca
 * cambian el perfil. Elegir el de omisión de la ranura la olvida, para seguirlo cuando cambie. Sin periodo, el selector
 * muestra "Sin periodo". Después lo confirma con un toast y recarga la página, con el campus que la página tenía elegido
 * (`?campus=`), para que todo lo que depende del periodo lo use. Si el periodo no existe, avisa con un toast de error y
 * regresa la selección al que rige.
 */
new class extends Component
{
    use Toast;

    public ?string $roleValue = null;

    public ?string $termId = null;

    public ?string $loadTermId = null;

    /** @var list<array{id: string, name: string}> un renglón por puesto, con dos o más; si no, ninguno */
    #[Locked]
    public array $roles = [];

    public function mount(Actor $context, RoleSummaryQuery $summaries): void
    {
        $this->roleValue = $context->activeRole()?->value;
        $this->termId = $context->viewTerm()?->getKey();
        $this->loadTermId = $context->loadTerm()?->getKey();

        $rows = count($context->heldRoles()) > 1 ? $summaries->forHeldRoles($context) : [];
        $this->roles = array_map(fn (string $value, string $summary): array => ['id' => $value, 'name' => $summary], array_keys($rows), $rows);
    }

    /**
     * @return array{terms: Collection<int, Term>, worksLoad: bool}
     */
    public function with(Actor $context, TermQuery $terms): array
    {
        return [
            'terms' => $context->user() === null ? new Collection : $terms->selectable(),
            'worksLoad' => $context->worksLoad(),
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
     * Guarda el periodo de consulta elegido. El de omisión es el que está en curso. No toca el perfil: eso es de
     * switchRole().
     */
    public function applyView(SetActiveContext $setActiveContext, Actor $context, TermQuery $terms): void
    {
        try {
            $term = blank($this->termId) ? null : $terms->findOrFail($this->termId);
            $default = $terms->inProgress(CarbonImmutable::now('America/Ciudad_Juarez'));

            $setActiveContext->handle($term !== null && $term->is($default) ? null : $term, TermSlot::View);
        } catch (ModelNotFoundException) {
            $this->reject('El periodo elegido ya no existe.', $context);

            return;
        } catch (AuthorizationException $exception) {
            $this->reject($exception->getMessage(), $context);

            return;
        }

        // El toast de MaryUI pinta título y descripción con x-html: el nombre va escapado.
        $this->success('Periodo actualizado', e((string) $context->viewTerm()?->name), redirectTo: $this->pageUrl());
    }

    /**
     * Guarda el periodo de carga elegido; solo quien trabaja la carga (Actor::worksLoad()). El de omisión es el vigente
     * (`is_current`).
     */
    public function applyLoad(SetActiveContext $setActiveContext, Actor $context, TermQuery $terms): void
    {
        try {
            if (! $context->worksLoad()) {
                throw new AuthorizationException('Tu puesto no trabaja la carga.');
            }

            $term = blank($this->loadTermId) ? null : $terms->findOrFail($this->loadTermId);

            $setActiveContext->handle($term?->is_current ? null : $term, TermSlot::Load);
        } catch (ModelNotFoundException) {
            $this->reject('El periodo elegido ya no existe.', $context);

            return;
        } catch (AuthorizationException $exception) {
            $this->reject($exception->getMessage(), $context);

            return;
        }

        // El toast de MaryUI pinta título y descripción con x-html: el nombre va escapado.
        $this->success('Periodo de carga actualizado', e((string) $context->loadTerm()?->name), redirectTo: $this->pageUrl());
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
        $this->termId = $context->viewTerm()?->getKey();
        $this->loadTermId = $context->loadTerm()?->getKey();

        $this->error('No se cambió el periodo', e($reason));
    }
};
