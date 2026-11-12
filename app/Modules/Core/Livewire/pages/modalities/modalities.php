<?php

use App\Modules\Core\Actions\CreateModality;
use App\Modules\Core\Actions\UpdateModality;
use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Enums\CorePermission;
use App\Modules\Core\Enums\StaffingMode;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\ModalityProfile;
use App\Modules\Core\Queries\ModalityQuery;
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
 * Modalidades (tarea 14, maqueta U4 opción A «Catálogo junto a Campus»; MS1, supuesto por confirmar): el catálogo con la
 * forma de Campus, una tabla con búsqueda y filtro y un panel lateral (x-drawer, ADR-0023) que edita la clave, el
 * nombre, el estado y el perfil de flujo con las claves de ModalityProfile. Activar o desactivar también se hace desde
 * la tabla. No hay borrar: es un catálogo.
 *
 * El componente lee con ModalityQuery, autoriza con ModalityPolicy en mount() y en cada acción (una petición puede
 * llegar con una página que abrió otra sesión), valida la forma de los datos y llama a CreateModality o UpdateModality,
 * que hacen cumplir las reglas (ModalityRules). La subdirección solo cambia el perfil de flujo: lo demás no se le envía a
 * la Action aunque llegue del navegador, y en pantalla se muestra deshabilitado con su motivo. Mientras Scheduling lea
 * `campuses.settings`, UpdateModality escribe el perfil también en los campus de la modalidad (temporal).
 */
new #[Title('Modalidades')] class extends Component
{
    use Toast, WithPagination;

    /** Búsqueda por clave o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todas), 'active' o 'inactive'; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    /** `showDrawer` no puede ser #[Locked]: el x-drawer lo cierra desde el navegador. */
    public bool $showDrawer = false;

    /** La modalidad que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    /** Si el panel deja cambiar clave, nombre y estado; save() lo vuelve a decidir con la policy. */
    #[Locked]
    public bool $canEditData = true;

    /** Si el perfil guardado era inválido y el panel trae los valores por omisión. */
    #[Locked]
    public bool $profileWasReset = false;

    public string $code = '';

    public string $name = '';

    public bool $isActive = true;

    /** @var array<string, mixed> el perfil de flujo, con las claves de ModalityProfile::DEFAULTS */
    public array $settings = ModalityProfile::DEFAULTS;

    public function mount(): void
    {
        $this->authorize('viewAny', Modality::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(ModalityQuery $modalities, Actor $context): array
    {
        $page = $modalities->forTable($context, $this->search, match ($this->status) {
            'active' => true,
            'inactive' => false,
            default => null,
        });

        return [
            'modalities' => $page,
            'profiles' => $this->profileSummaries($page),
            'canCreate' => Gate::allows('create', Modality::class),
            'canSeeInactive' => $context->allows(CorePermission::ModalityViewInactive),
            'headers' => [
                ['key' => 'code', 'label' => 'Clave'],
                ['key' => 'name', 'label' => 'Modalidad', 'class' => 'min-w-36 min-[1200px]:min-w-48'],
                ['key' => 'profile', 'label' => 'Perfil de flujo', 'class' => 'max-[1023px]:hidden'],
                ['key' => 'is_active', 'label' => 'Estado'],
            ],
            // DESIGN.md, "Insignias de estado": la fila de una inactiva pasa a text-neutral.
            'rowDecoration' => ['text-neutral' => fn (Modality $modality): bool => ! $modality->is_active],
            'statuses' => [['id' => 'active', 'name' => 'Activas'], ['id' => 'inactive', 'name' => 'Inactivas']],
            'staffingModes' => array_map(fn (StaffingMode $mode): array => ['id' => $mode->value, 'name' => $mode->label()], StaffingMode::cases()),
            'days' => [1 => 'Lunes', 2 => 'Martes', 3 => 'Miércoles', 4 => 'Jueves', 5 => 'Viernes', 6 => 'Sábado'],
            'dayLetters' => [1 => 'L', 2 => 'M', 3 => 'Mi', 4 => 'J', 5 => 'V', 6 => 'S'],
            'summary' => $this->showDrawer ? $this->draftSummary() : null,
            'editingName' => $this->editingId === null ? null : Modality::query()->whereKey($this->editingId)->value('name'),
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
        $this->authorize('create', Modality::class);

        $this->resetValidation();
        $this->reset('editingId', 'canEditData', 'profileWasReset', 'code', 'name', 'isActive', 'settings');
        $this->showDrawer = true;
    }

    public function edit(string $id, ModalityQuery $modalities): void
    {
        $modality = $modalities->findOrFail($id);
        $this->authorize('updateProfile', $modality);

        $this->resetValidation();
        $this->editingId = $modality->id;
        $this->canEditData = Gate::allows('update', $modality);
        $this->code = $modality->code;
        $this->name = $modality->name;
        $this->isActive = $modality->is_active;
        $this->showDrawer = true;

        try {
            $this->settings = $modality->profile->toArray();
            $this->profileWasReset = false;
        } catch (InvalidArgumentException) {
            $this->settings = ModalityProfile::DEFAULTS;
            $this->profileWasReset = true;
        }
    }

    public function save(CreateModality $createModality, UpdateModality $updateModality, ModalityQuery $modalities): void
    {
        if ($this->editingId === null) {
            $this->authorize('create', Modality::class);
            $data = $this->validatedModality();
            $modality = $createModality->handle(['code' => $data['code'], 'name' => $data['name'], 'settings' => $data['settings']]);
            $this->success('Modalidad creada', $this->describe($modality));
        } else {
            $modality = $modalities->findOrFail($this->editingId);

            if (Gate::allows('update', $modality)) {
                $data = $this->validatedModality();
            } else {
                $this->authorize('updateProfile', $modality);
                $data = ['settings' => $this->validatedProfile()];
            }

            $modality = $updateModality->handle($modality, $data);
            $this->success('Modalidad actualizada', $this->describe($modality));
        }

        $this->showDrawer = false;
    }

    public function toggleActive(string $id, ModalityQuery $modalities, UpdateModality $updateModality): void
    {
        $modality = $modalities->findOrFail($id);
        $this->authorize('update', $modality);

        $modality = $updateModality->handle($modality, ['is_active' => ! $modality->is_active]);

        $this->success($modality->is_active ? 'Modalidad activada' : 'Modalidad desactivada', $this->describe($modality));
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
            'code' => 'clave',
            'name' => 'nombre',
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
     * Datos, estado y perfil, validados en forma y con los tipos que espera la Action.
     *
     * @return array{code: string, name: string, is_active: bool, settings: array<string, mixed>}
     */
    private function validatedModality(): array
    {
        $validated = $this->validate([
            'code' => ['required', 'string', 'max:255'],
            'name' => ['required', 'string', 'max:255'],
            'isActive' => ['boolean'],
            ...$this->profileRules(),
        ]);

        return [
            'code' => $validated['code'],
            'name' => $validated['name'],
            'is_active' => (bool) $validated['isActive'],
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
     * Solo la forma: lo que cruza campos (la jornada y su división en bloques) lo decide ModalityProfile en la Action.
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
     * El perfil validado con los tipos de ModalityProfile: el formulario entrega números como texto y los días pueden
     * llegar con huecos en las claves.
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
     * El perfil de cada modalidad de la página en una línea, por id; null si el guardado no es válido: la tabla lo
     * marca y el panel lo repara al editar.
     *
     * @param  LengthAwarePaginator<int, Modality>  $modalities
     * @return array<string, ?string>
     */
    private function profileSummaries(LengthAwarePaginator $modalities): array
    {
        $summaries = [];

        foreach ($modalities as $modality) {
            try {
                $summaries[$modality->id] = $modality->profile->summary();
            } catch (InvalidArgumentException) {
                $summaries[$modality->id] = null;
            }
        }

        return $summaries;
    }

    /**
     * El perfil que se está editando en el panel, en una línea; null mientras lo escrito no forme un perfil válido.
     */
    private function draftSummary(): ?string
    {
        try {
            return ModalityProfile::fromArray([
                ...$this->settings,
                'block_minutes' => (int) ($this->settings['block_minutes'] ?? 0),
                'days' => array_values(array_map(intval(...), (array) ($this->settings['days'] ?? []))),
                'uses_rooms' => (bool) ($this->settings['uses_rooms'] ?? false),
                'uses_time_blocks' => (bool) ($this->settings['uses_time_blocks'] ?? false),
                'allow_external_teachers' => (bool) ($this->settings['allow_external_teachers'] ?? false),
                'requires_subdireccion_approval' => (bool) ($this->settings['requires_subdireccion_approval'] ?? false),
            ])->summary();
        } catch (InvalidArgumentException|ValueError|TypeError) {
            return null;
        }
    }

    /**
     * Clave y nombre para el toast, escapados: MaryUI lo pinta con x-html.
     */
    private function describe(Modality $modality): string
    {
        return e("{$modality->code} · {$modality->name}");
    }
};
