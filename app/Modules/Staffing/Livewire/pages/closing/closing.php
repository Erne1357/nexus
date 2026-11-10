<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Staffing\Livewire\Concerns\ClosesLoads;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Cierre de la carga (tarea 4.1; reglas-de-negocio.md, "Cierre y reapertura de la carga"; maqueta B2, elegida por el
 * usuario el 10 de noviembre de 2026): la carga de un departamento en el periodo activo, con una pestaña por campus
 * donde tiene grupos (con una sola, sin pestañas). De la carga elegida: la línea de estados, lo que le falta para cerrar
 * en cifras con la pantalla donde se arregla, las justificaciones de los grupos sin docente o sin salón
 * (`staffing::components.closing-justifications`, con su drawer aparte, `closing-justify-drawer`: los dos avisan con
 * `closing-justified` y aquí se revisa otra vez lo que falta), el historial y sus acciones (ClosesLoads).
 *
 * Entra quien cierra la carga de algún departamento (`academic_load.load.close`: el jefe y Subdirección, y el admin);
 * el jefe va a su departamento y Subdirección y el admin eligen (`?departamento=<id>`, como llegan los avisos del
 * cierre). Un departamento que no puede cerrar es 403. `?campus=<código>` elige la pestaña; sin él, la del campus de la
 * credencial si tiene grupos ahí, o la primera.
 */
new #[Title('Cierre de la carga')] class extends Component
{
    use ClosesLoads, Toast;

    /** El id del departamento; '' es el primero que puede ver. */
    #[Url(as: 'departamento')]
    public string $department = '';

    /** El código del campus de la pestaña; '' es el de la credencial o el primero. */
    #[Url(as: 'campus')]
    public string $campus = '';

    #[Locked]
    public ?string $termId = null;

    #[Locked]
    public ?string $departmentId = null;

    /**
     * Los departamentos del selector (los que tienen grupos), para quien ve varios.
     *
     * @var list<array{id: string, name: string}>
     */
    #[Locked]
    public array $departments = [];

    /** Por qué no hay carga que mostrar. */
    #[Locked]
    public ?string $message = null;

    public function mount(Actor $context, LoadClosingQuery $query): void
    {
        abort_unless($context->allowsAny(AcademicLoadPermission::LoadClose), 403);

        $this->resolve($context, $query);
    }

    public function updatedDepartment(Actor $context, LoadClosingQuery $query): void
    {
        $this->reset('campus');
        $this->resolve($context, $query);
    }

    /**
     * Elige la pestaña de otro campus del mismo departamento.
     */
    public function selectCampus(string $code, Actor $context, LoadClosingQuery $query): void
    {
        $this->campus = $code;
        $this->resolve($context, $query);
    }

    /**
     * Se justificó un grupo (en su drawer) o varios (en la lista): revisa otra vez lo que falta y, si fue uno, lo dice.
     */
    #[On('closing-justified')]
    public function justified(?string $code = null, bool $removed = false, bool $updated = false): void
    {
        $query = app(LoadClosingQuery::class);
        $load = $this->closingLoad($query);
        $this->checkBlockers($load, $query);

        if ($code === null) {
            return;
        }

        if ($removed) {
            $this->warning('Justificación quitada', e("{$code} vuelve a contar como faltante."));

            return;
        }

        $closing = $query->of($load, $this->cachedBlockers());
        $this->success($updated ? 'Justificación actualizada' : 'Grupo justificado', e($code.' · '.($closing->missing() > 0 ? mb_strtolower($closing->missingLabel()).' para cerrar' : 'la carga está lista para cerrar')));
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadClosingQuery $query): array
    {
        if ($this->loadId === null) {
            return ['closing' => null, 'tabs' => collect(), 'actions' => []];
        }

        $load = $this->closingLoad($query);
        $closing = $query->of($load, $this->cachedBlockers());

        return [
            'closing' => $closing,
            'tabs' => $query->loadsOf($load->term_id, $load->department_id),
            'actions' => $this->loadActions($closing),
        ];
    }

    protected function authorizeLoad(AcademicLoad $load): void
    {
        abort_unless($load->getKey() === $this->loadId && $load->department_id === $this->departmentId, 403);
        Gate::authorize('close', $load);
    }

    /**
     * Resuelve con la credencial el periodo, el departamento y la pestaña, y deja en propiedades bloqueadas con qué
     * pinta la página (o por qué no hay carga). Un departamento pedido que el usuario no puede cerrar es 403.
     */
    private function resolve(Actor $context, LoadClosingQuery $query): void
    {
        $this->termId = $this->departmentId = null;
        $this->departments = [];
        $this->showLoad(null, $query);
        $term = $context->activeTerm();

        if ($term === null) {
            $this->message = 'No hay un periodo activo: elige uno en tu credencial.';

            return;
        }

        $departments = $query->departmentsFor($context, $term->getKey());
        $department = $this->department === ''
            ? $departments->firstWhere('has_groups', true) ?? $departments->first()
            : $departments->firstWhere('id', $this->department);

        abort_if($this->department !== '' && $department === null, 403);

        $this->departments = $departments->filter(fn (Department $option): bool => $option->has_groups || $option->is($department))
            ->map(fn (Department $option): array => ['id' => $option->id, 'name' => "{$option->code} · {$option->name}"])->values()->all();

        if ($department === null) {
            $this->message = "No tienes un departamento con carga en {$term->name}.";

            return;
        }

        $this->termId = $term->getKey();
        $this->departmentId = $department->getKey();
        $this->department = $department->getKey();
        $loads = $query->loadsOf($term->getKey(), $department->getKey());
        $load = $loads->first(fn (AcademicLoad $candidate): bool => $candidate->campus->code === $this->campus)
            ?? $loads->first(fn (AcademicLoad $candidate): bool => $candidate->campus_id === $context->activeCampus()?->getKey())
            ?? $loads->first();

        if ($load === null) {
            $this->campus = '';
            $this->message = "{$department->name} no tiene grupos en {$term->name}: su carga se queda en borrador.";

            return;
        }

        $this->campus = $load->campus->code;
        $this->message = null;
        $this->showLoad($load, $query);
    }
};
