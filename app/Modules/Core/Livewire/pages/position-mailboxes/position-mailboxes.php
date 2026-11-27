<?php

use App\Modules\Core\Actions\DeactivatePositionMailbox;
use App\Modules\Core\Actions\SavePositionMailbox;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\PositionRef;
use App\Modules\Core\Queries\PositionMailboxQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * «Buzones de puesto» (CE-02, tarea 1.63), en Configuración y solo del admin (`core.mail.view_mailboxes` y
 * `core.mail.manage_mailboxes`, candados): a qué buzón institucional va el correo de cada puesto. Arriba, si hay, los
 * correos que esperan porque su puesto no tiene buzón, agrupados por puesto, cada grupo con «Capturar buzón» (abre el
 * cajón con ese puesto ya elegido); abajo, la tabla de buzones con su puesto y alcance, el correo, cuántos correos en
 * espera recibiría y su estado, con «Editar» y, en «Acciones», «Desactivar» con confirmación. El cajón pide el puesto,
 * solo el alcance que el puesto admite (vacío es «todas») y el correo institucional.
 *
 * Lee con PositionMailboxQuery, autoriza con PositionMailboxPolicy en mount() y en cada acción, valida la forma y llama a
 * SavePositionMailbox o DeactivatePositionMailbox, que hacen cumplir las reglas (PositionMailboxRules).
 */
new #[Title('Buzones de puesto')] class extends Component
{
    use Toast;

    /** Búsqueda por correo o puesto; se recuerda en la sesión del usuario. */
    #[Session]
    public string $search = '';

    public bool $showDrawer = false;

    /** El buzón que se edita; null es uno nuevo. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingId = null;

    public string $role = '';

    public string $unit = '';

    public string $career = '';

    public string $campus = '';

    public string $modality = '';

    public string $email = '';

    public function mount(): void
    {
        $this->authorize('viewAny', PositionMailbox::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PositionMailboxQuery $mailboxes): array
    {
        $waiting = $mailboxes->waiting();
        $roleCase = Role::tryFrom($this->role);
        $columns = $roleCase === null ? [] : PositionRef::columnsOf($roleCase);

        return [
            'rows' => $mailboxes->forTable($this->search, $waiting),
            'unattended' => array_values(array_filter($waiting, fn (array $group): bool => $group['mailbox'] === null)),
            'canManage' => Gate::allows('create', PositionMailbox::class),
            'roles' => $mailboxes->roleOptions(),
            'roleCase' => $roleCase,
            'columns' => $columns,
            'options' => $roleCase === null
                ? ['modalities' => [], 'campuses' => [], 'units' => [], 'careers' => []]
                : $mailboxes->scopeOptions($roleCase, $this->campus === '' ? null : $this->campus, $this->modality === '' ? null : $this->modality),
            'domains' => InstitutionalMail::domains(),
            'headers' => [
                ['key' => 'position', 'label' => 'Puesto', 'class' => 'min-w-56'],
                ['key' => 'email', 'label' => 'Buzón'],
                ['key' => 'waiting', 'label' => 'En espera', 'class' => 'max-[1200px]:hidden'],
                ['key' => 'status', 'label' => 'Estado'],
            ],
        ];
    }

    /**
     * Al cambiar de puesto se vacía el alcance, porque otro puesto lleva otras columnas.
     */
    public function updatedRole(): void
    {
        $this->reset('unit', 'career', 'campus', 'modality');
        $this->resetValidation(['unit', 'career', 'campus', 'modality']);
    }

    public function create(): void
    {
        $this->authorize('create', PositionMailbox::class);

        $this->openDrawer();
    }

    /**
     * «Capturar buzón» de un grupo en espera: el cajón con su puesto y alcance ya elegidos (`$key`, PositionRef::key()).
     * Lo que llegue mal se queda vacío: SavePositionMailbox revisa todo al guardar.
     */
    public function capture(string $key): void
    {
        $this->authorize('create', PositionMailbox::class);

        $this->openDrawer();
        $parts = explode('|', $key);
        $this->role = Role::tryFrom($parts[0] ?? '')?->value ?? '';

        foreach (['unit', 'career', 'campus', 'modality'] as $index => $field) {
            $value = $parts[$index + 1] ?? '*';
            $this->{$field} = $value === '*' || ! Str::isUuid($value) ? '' : $value;
        }
    }

    public function edit(string $id, PositionMailboxQuery $mailboxes): void
    {
        $mailbox = $mailboxes->findOrFail($id);
        $this->authorize('update', $mailbox);

        $this->openDrawer();
        $this->editingId = $mailbox->id;
        $this->role = $mailbox->role->value;
        $this->unit = (string) $mailbox->org_unit_id;
        $this->career = (string) $mailbox->career_id;
        $this->campus = (string) $mailbox->campus_id;
        $this->modality = (string) $mailbox->modality_id;
        $this->email = $mailbox->email;
    }

    public function save(SavePositionMailbox $save, PositionMailboxQuery $mailboxes): void
    {
        $mailbox = $this->editingId === null ? null : $mailboxes->findOrFail($this->editingId);
        $mailbox === null ? $this->authorize('create', PositionMailbox::class) : $this->authorize('update', $mailbox);

        $this->validate([
            'role' => ['required', 'string'],
            'email' => ['required', 'string', 'max:255'],
        ]);

        $saved = $save->handle([
            'role' => $this->role,
            'org_unit_id' => $this->unit,
            'career_id' => $this->career,
            'campus_id' => $this->campus,
            'modality_id' => $this->modality,
            'email' => $this->email,
        ], $mailbox, auth()->user());

        $this->showDrawer = false;
        $this->success($mailbox === null ? 'Buzón capturado' : 'Buzón guardado', e("{$saved->email} · {$saved->role->label()}. Los correos que lo esperaban salen en unos momentos."));
    }

    public function deactivate(string $id, PositionMailboxQuery $mailboxes, DeactivatePositionMailbox $deactivate): void
    {
        $mailbox = $mailboxes->findOrFail($id);
        $this->authorize('deactivate', $mailbox);

        $deactivate->handle($mailbox, auth()->user());

        $this->success('Buzón desactivado', e("{$mailbox->email}. Los correos nuevos de su puesto esperan hasta que tenga buzón."));
    }

    /**
     * @return array<string, string>
     */
    protected function validationAttributes(): array
    {
        return ['role' => 'puesto', 'email' => 'correo del buzón'];
    }

    /**
     * @return array<string, string>
     */
    protected function messages(): array
    {
        return ['role.required' => 'Elige el puesto.', 'email.required' => 'Escribe el correo institucional del buzón.'];
    }

    private function openDrawer(): void
    {
        $this->resetValidation();
        $this->reset('editingId', 'role', 'unit', 'career', 'campus', 'modality', 'email');
        $this->showDrawer = true;
    }
};
