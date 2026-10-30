<?php

use App\Modules\Core\Actions\CreateCampus;
use App\Modules\Core\Actions\UpdateCampus;
use App\Modules\Core\Enums\Modality;
use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\CampusProfile;
use App\Modules\Core\Queries\CampusQuery;
use App\Modules\Core\Queries\ScopedContext;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * CRUD de Campus, el módulo de referencia: tabla con búsqueda y filtro, alta y edición en un modal y
 * activar o desactivar desde la tabla. No hay borrar: es un catálogo.
 *
 * El componente lee con CampusQuery, autoriza con CampusPolicy en mount() y en cada acción (una
 * petición puede llegar con una página que abrió otra sesión), valida la forma de los datos y llama a
 * CreateCampus o UpdateCampus, que hacen cumplir las reglas del campus. La subdirección solo cambia el
 * perfil de flujo: lo demás no se le envía a la Action aunque llegue del navegador, y en pantalla se
 * muestra deshabilitado con su motivo, igual que el filtro de inactivos, que solo ve el admin.
 */
new #[Title('Campus')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por código o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos), 'active' o 'inactive'; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    public bool $showModal = false;

    /** El campus que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    /** Si el formulario deja cambiar código, nombre y modalidad; save() lo vuelve a decidir con la policy. */
    #[Locked]
    public bool $canEditData = true;

    /** Si el perfil guardado era inválido y el formulario trae los valores por omisión. */
    #[Locked]
    public bool $profileWasReset = false;

    public string $code = '';

    public string $name = '';

    public string $modality = Modality::Presencial->value;

    /** @var array<string, mixed> el perfil de flujo, con las claves de CampusProfile::DEFAULTS */
    public array $settings = CampusProfile::DEFAULTS;

    public function mount(): void
    {
        $this->authorize('viewAny', Campus::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(CampusQuery $campuses, ScopedContext $context): array
    {
        $page = $campuses->forTable($context, $this->search, match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        });

        return [
            'campuses' => $page,
            'profiles' => $this->profileSummaries($page),
            'canCreate' => Gate::allows('create', Campus::class),
            'canSeeInactive' => $context->isAdmin(),
            // El perfil es la columna descriptiva larga: se oculta debajo de 1200 px (DESIGN.md, "Responsivo").
            'headers' => [
                ['key' => 'code', 'label' => 'Código'],
                ['key' => 'name', 'label' => 'Nombre', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'modality', 'label' => 'Modalidad', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'profile', 'label' => 'Perfil de flujo', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de un inactivo pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Campus $campus): bool => ! $campus->is_active],
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'modalities' => array_map(fn (Modality $modality): array => ['id' => $modality->value, 'name' => $modality->label()], Modality::cases()),
            'staffingModes' => array_map(fn (StaffingMode $mode): array => ['id' => $mode->value, 'name' => $mode->label()], StaffingMode::cases()),
            'days' => [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'],
        ];
    }

    public function updated(string $property): void
    {
        if (in_array($property, ['search', 'status'], true)) {
            $this->resetPage();
        }
    }

    public function create(): void
    {
        $this->authorize('create', Campus::class);

        $this->resetValidation();
        $this->reset('editingId', 'canEditData', 'profileWasReset', 'code', 'name', 'modality', 'settings');
        $this->showModal = true;
    }

    public function edit(string $id, CampusQuery $campuses): void
    {
        $campus = $campuses->findOrFail($id);
        $this->authorize('updateProfile', $campus);

        $this->resetValidation();
        $this->editingId = $campus->id;
        $this->canEditData = Gate::allows('update', $campus);
        $this->code = $campus->code;
        $this->name = $campus->name;
        $this->modality = $campus->modality->value;
        $this->showModal = true;

        try {
            $this->settings = $campus->profile->toArray();
            $this->profileWasReset = false;
        } catch (InvalidArgumentException) {
            $this->settings = CampusProfile::DEFAULTS;
            $this->profileWasReset = true;
        }
    }

    public function save(CreateCampus $createCampus, UpdateCampus $updateCampus, CampusQuery $campuses): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Campus::class);
            $campus = $createCampus->handle($this->validatedCampus());
            $this->success('Campus creado', $this->describe($campus));
        } else {
            $campus = $campuses->findOrFail($this->editingId);

            if (Gate::allows('update', $campus)) {
                $data = $this->validatedCampus();
            } else {
                $this->authorize('updateProfile', $campus);
                $data = ['settings' => $this->validatedProfile()];
            }

            $campus = $updateCampus->handle($campus, $data);
            $this->success('Campus actualizado', $this->describe($campus));
        }

        $this->showModal = false;
    }

    public function toggleActive(string $id, CampusQuery $campuses, UpdateCampus $updateCampus): void
    {
        $campus = $campuses->findOrFail($id);
        $this->authorize('update', $campus);

        $campus = $updateCampus->handle($campus, ['is_active' => ! $campus->is_active]);

        $this->success($campus->is_active ? 'Campus activado' : 'Campus desactivado', $this->describe($campus));
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return [
            'settings.day_start.date_format' => 'El campo :attribute debe ser una hora con el formato HH:MM.',
            'settings.day_end.date_format' => 'El campo :attribute debe ser una hora con el formato HH:MM.',
            'settings.block_minutes.min' => 'El campo :attribute debe ser al menos :min.',
        ];
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return [
            'code' => 'código',
            'name' => 'nombre',
            'modality' => 'modalidad',
            'settings.uses_rooms' => 'usa salones',
            'settings.uses_time_blocks' => 'usa bloques de horario',
            'settings.staffing_mode' => 'asignación docente',
            'settings.block_minutes' => 'duración del bloque',
            'settings.day_start' => 'inicio de la jornada',
            'settings.day_end' => 'fin de la jornada',
            'settings.days' => 'días',
            'settings.days.*' => 'día',
            'settings.allow_external_teachers' => 'admite docentes externos',
            'settings.requires_subdireccion_approval' => 'requiere visto bueno de Subdirección',
        ];
    }

    /**
     * Datos y perfil, validados en forma y con los tipos que espera la Action.
     *
     * @return array{code: string, name: string, modality: string, settings: array<string, mixed>}
     */
    private function validatedCampus(): array
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'modality' => ['required', Rule::enum(Modality::class)],
            ...$this->profileRules(),
        ]);

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'modality' => $validated['modality'],
            'settings' => $this->profile($validated['settings']),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function validatedProfile(): array
    {
        return $this->profile($this->validate($this->profileRules())['settings']);
    }

    /**
     * Solo la forma: lo que cruza campos (la jornada y su división en bloques) lo decide CampusProfile
     * en la Action.
     *
     * @return array<string, list<mixed>>
     */
    private function profileRules(): array
    {
        return [
            'settings.uses_rooms' => ['required', 'boolean'],
            'settings.uses_time_blocks' => ['required', 'boolean'],
            'settings.staffing_mode' => ['required', Rule::enum(StaffingMode::class)],
            'settings.block_minutes' => ['required', 'integer', 'min:1'],
            'settings.day_start' => ['required', 'date_format:H:i'],
            'settings.day_end' => ['required', 'date_format:H:i'],
            'settings.days' => ['required', 'array'],
            'settings.days.*' => ['integer'],
            'settings.allow_external_teachers' => ['required', 'boolean'],
            'settings.requires_subdireccion_approval' => ['required', 'boolean'],
        ];
    }

    /**
     * El perfil validado con los tipos de CampusProfile: el formulario entrega números como texto y
     * los días pueden llegar con huecos en las claves.
     *
     * @param  array<string, mixed>  $settings
     * @return array<string, mixed>
     */
    private function profile(array $settings): array
    {
        return [
            'uses_rooms' => (bool) $settings['uses_rooms'],
            'uses_time_blocks' => (bool) $settings['uses_time_blocks'],
            'staffing_mode' => $settings['staffing_mode'],
            'block_minutes' => (int) $settings['block_minutes'],
            'day_start' => $settings['day_start'],
            'day_end' => $settings['day_end'],
            'days' => array_values(array_map(intval(...), $settings['days'])),
            'allow_external_teachers' => (bool) $settings['allow_external_teachers'],
            'requires_subdireccion_approval' => (bool) $settings['requires_subdireccion_approval'],
        ];
    }

    /**
     * El perfil de cada campus de la página en una línea, por id; null si el guardado no es válido: la
     * tabla lo marca y el modal lo repara al editar.
     *
     * @param  LengthAwarePaginator<int, Campus>  $campuses
     * @return array<string, ?string>
     */
    private function profileSummaries(LengthAwarePaginator $campuses): array
    {
        $summaries = [];

        foreach ($campuses as $campus) {
            try {
                $summaries[$campus->id] = $campus->profile->summary();
            } catch (InvalidArgumentException) {
                $summaries[$campus->id] = null;
            }
        }

        return $summaries;
    }

    /**
     * Código y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Campus $campus): string
    {
        return e("{$campus->code} · {$campus->name}");
    }
};
