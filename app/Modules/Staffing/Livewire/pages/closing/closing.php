<?php

use App\Modules\Academic\Models\Department;
use App\Modules\AcademicLoad\Enums\AcademicLoadPermission;
use App\Modules\AcademicLoad\Models\AcademicLoad;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Livewire\Concerns\WithModalityCampusFilter;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Scheduling\Queries\CampusGroupCountsQuery;
use App\Modules\Staffing\Livewire\Concerns\ClosesLoads;
use App\Modules\Staffing\Queries\LoadClosingQuery;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Locked;
use Livewire\Attributes\On;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * Cierre de la carga (tarea 4.1; reglas-de-negocio.md, "Cierre y reapertura de la carga"; maqueta B2, elegida por el
 * usuario el 10 de noviembre de 2026): la carga de un departamento en el periodo activo y en la modalidad de la pantalla
 * (`?modalidad=`, WithModalityCampusFilter; M1: una carga con los grupos de todos sus campus), que se elige con el select
 * «Modalidad» (maqueta U2, opción A; sin select con una sola, MS16). Sin elegir, abre en la primera modalidad donde el
 * departamento tiene grupos (MS30). De la carga elegida: la línea de estados y, debajo, la publicación
 * con su línea de versiones (`academic-load::components.publish-load`, que avisa con `load-published`), lo que le falta
 * para cerrar en cifras con la pantalla donde se arregla, las justificaciones de los grupos sin docente o sin salón
 * (`staffing::components.closing-justifications`, con su drawer aparte, `closing-justify-drawer`: los dos avisan con
 * `closing-justified` y aquí se revisa otra vez lo que falta), el historial y sus acciones (ClosesLoads).
 *
 * Entra quien cierra la carga de algún departamento (`academic_load.load.close`: el jefe y Subdirección, y el admin);
 * el jefe va a su departamento y Subdirección y el admin eligen (`?departamento=<id>`, como llegan los avisos del
 * cierre). Un departamento que no puede cerrar es 403; uno que sí, pero sin carga en el periodo, lo dice. `?campus=` es
 * un filtro de las justificaciones (los grupos de ese campus); sin él, las de todos los campus de la carga. Sus opciones
 * son los campus de la modalidad, cada uno con los grupos de la carga que se dan ahí (MS6); el cierre es de toda la carga.
 */
new #[Title('Cierre de la carga')] class extends Component
{
    use ClosesLoads, Toast, WithModalityCampusFilter;

    /** El id del departamento; '' es el primero que puede ver. */
    #[Url(as: 'departamento')]
    public string $department = '';

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
     * Otra modalidad en el select: la carga del mismo departamento en ella (el filtro de campus se conserva si la otra
     * modalidad lo tiene).
     */
    public function updatedModalidad(Actor $context, LoadClosingQuery $query): void
    {
        $this->resolve($context, $query);
    }

    /**
     * Otro campus en el filtro de las justificaciones: se recuerda para la siguiente pantalla (AZ-02, supuesto por
     * confirmar).
     */
    public function updatedCampus(): void
    {
        $chosen = $this->campusFilter();

        if ($chosen !== null) {
            session()->put(Campus::LAST_CHOSEN_KEY, $chosen->code);
        }
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
     * La publicación (academic-load::components.publish-load) publicó una versión de la carga a la vista: vuelve a leerla
     * para la línea de estados, la insignia y el historial, y ya no calcula lo que falta.
     */
    #[On('load-published')]
    public function published(string $loadId, int $version): void
    {
        if ($this->loadId === $loadId) {
            $this->refreshLoad(app(LoadClosingQuery::class));
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function with(LoadClosingQuery $query, CampusGroupCountsQuery $counts): array
    {
        $scope = [
            'modalityOptions' => $this->modalityOptions(),
            'campusOptions' => $this->termId === null ? collect() : $this->campusOptions(),
            'campusFilter' => $this->termId === null ? null : $this->campusFilter(),
        ];

        if ($this->loadId === null) {
            return ['closing' => null, 'actions' => [], 'canViewLoad' => false, 'campusCounts' => [], 'campusSplit' => null, ...$scope];
        }

        $load = $this->closingLoad($query);
        $closing = $query->of($load, $this->cachedBlockers());
        $byCampus = $counts->ofLoad($load);

        return [
            'closing' => $closing,
            'actions' => $this->loadActions($closing),
            'canViewLoad' => Gate::allows('view', $load),
            'campusCounts' => $byCampus,
            'campusSplit' => $this->campusSplit($byCampus),
            ...$scope,
        ];
    }

    /**
     * «Una sola carga: 2 grupos en C1 y 1 en C2. Se cierra y se publica una vez.» cuando la carga tiene grupos en más
     * de un campus (M1); si no, null.
     *
     * @param  array<string, int>  $byCampus
     */
    private function campusSplit(array $byCampus): ?string
    {
        $parts = array_values(array_filter($byCampus, fn (int $total): bool => $total > 0));

        if (count($parts) < 2) {
            return null;
        }

        $codes = array_keys(array_filter($byCampus, fn (int $total): bool => $total > 0));
        $phrases = array_map(fn (int $index): string => $index === 0
            ? $parts[0].' '.($parts[0] === 1 ? 'grupo' : 'grupos')." en {$codes[0]}"
            : "{$parts[$index]} en {$codes[$index]}", array_keys($parts));
        $last = array_pop($phrases);

        return 'Una sola carga: '.implode(', ', $phrases)." y {$last}. Se cierra y se publica una vez.";
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

        if ($this->department !== '' && $department === null) {
            // Uno que puede cerrar pero sin carga en el periodo (el del jefe, sin filas en academic_loads) lo dice; uno
            // ajeno es 403.
            $own = $query->closableDepartment($context, $this->department, $this->requiredModality()->getKey()) ?? abort(403);
            $this->departments = $departments->filter(fn (Department $option): bool => $option->has_groups)
                ->map(fn (Department $option): array => ['id' => $option->id, 'name' => "{$option->code} · {$option->name}"])->values()->all();
            $this->message = "{$own->name} no tiene carga en {$term->name}.";

            return;
        }

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
        $modality = $this->modalityOf($loads);
        $load = $loads->first(fn (AcademicLoad $candidate): bool => $candidate->modality_id === $modality->getKey());

        if ($load === null) {
            $this->message = "{$department->name} no tiene grupos en {$modality->name} en {$term->name}: su carga se queda en borrador.";

            return;
        }

        $this->modalidad = $modality->code;
        $this->message = null;
        $this->showLoad($load, $query);
    }

    /**
     * La modalidad de la pantalla: la elegida si está entre las opciones; sin elegir (o una que no se ofrece), la
     * primera por clave donde el departamento tiene una carga con grupos, para no abrir en una modalidad vacía (MS30); si
     * no tiene ninguna, la de omisión de WithModalityScope.
     *
     * @param  Collection<int, AcademicLoad>  $loads  las del departamento con grupos, por clave de la modalidad
     */
    private function modalityOf(Collection $loads): Modality
    {
        $options = $this->modalityOptions();
        $chosen = blank($this->modalidad) ? null : $options->firstWhere('code', $this->modalidad);

        if ($chosen !== null) {
            return $chosen;
        }

        $withGroups = $loads->first(fn (AcademicLoad $load): bool => $options->contains(fn (Modality $option): bool => $option->getKey() === $load->modality_id));

        return $withGroups === null ? $this->requiredModality() : $options->first(fn (Modality $option): bool => $option->getKey() === $withGroups->modality_id);
    }
};
