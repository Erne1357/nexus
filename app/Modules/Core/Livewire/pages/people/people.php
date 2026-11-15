<?php

use App\Modules\Core\Actions\CreateStaffUser;
use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Enums\UserAccess;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\AppointmentsQuery;
use App\Modules\Core\Queries\AssignmentLabels;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Core\Rules\UserAccountRules;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;
use Mary\Traits\Toast;

/**
 * Lista de «Personas y puestos» (3.P7, maqueta C): todos los usuarios con sus puestos vigentes y el estado de su acceso
 * (AC-04), con búsqueda por número o nombre y filtros por puesto, por estado de la cuenta y por acceso (1.22-C1).
 * Nombrar y terminar se hacen en la ficha de la persona (core::pages.person), a la que lleva «Ver puestos». La acción
 * principal es «Dar de alta» (CF-S3, UserPolicy): un cajón pide usuario, nombre y correo institucional opcional,
 * CreateStaffUser la da de alta sin acceso, con correo le llega la invitación (InviteUser, CF-07) y la pantalla lleva a
 * su ficha.
 * Solo la ve el admin con ese perfil activo (RoleAssignmentPolicy);
 * el componente autoriza en mount(), al renderizar y en cada cambio, porque una petición puede llegar con una página
 * que abrió otra sesión.
 *
 * La pestaña «Nombramientos» lista un renglón por nombramiento (AppointmentsQuery) con modalidad y campus
 * («Todos los campus» si no lo fija), y sus filtros: persona, puesto, modalidad, campus y vigencia.
 */
new #[Title('Personas y puestos')] class extends Component
{
    use Toast;
    use WithPagination;

    /** Búsqueda por usuario o nombre; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $search = '';

    /** '' (todos) o el valor de un Role; se recuerda en la sesión del usuario. */
    #[Session]
    public string $role = '';

    /** '' (todas), `active` o `inactive`; se recuerda en la sesión del usuario. */
    #[Session]
    public string $status = '';

    /** '' (todos) o el valor de un UserAccess; se recuerda en la sesión del usuario. */
    #[Session]
    public string $access = '';

    public bool $showCreate = false;

    public string $newUsername = '';

    public string $newName = '';

    public string $newEmail = '';

    /** `people` o `appointments`. */
    #[Session]
    public string $tab = 'people';

    public string $appSearch = '';

    public string $appRole = '';

    public string $appModality = '';

    /** '' (todos), `all` (los que valen en todos los campus) o el id de un campus. */
    public string $appCampus = '';

    /** `current` (vigentes hoy) o `all`. */
    public string $appCurrent = 'current';

    public function mount(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PeopleQuery $people, AssignmentLabels $labels, AppointmentsQuery $appointments): array
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if ($this->tab === 'appointments') {
            return $this->appointmentsData($people, $labels, $appointments);
        }

        $page = $people->forTable(
            $this->search,
            Role::tryFrom($this->role),
            active: match ($this->status) {
                'active' => true,
                'inactive' => false,
                default => null,
            },
            access: UserAccess::tryFrom($this->access),
        );
        $described = $labels->describe($page->getCollection()->flatMap(fn (User $user) => $user->roleAssignments));

        $summaries = [];
        $access = [];

        foreach ($page as $user) {
            $summaries[$user->id] = $user->roleAssignments->map(fn (RoleAssignment $assignment): string => $described[$assignment->getKey()]->summary)->all();
            $access[$user->id] = UserAccess::of($user);
        }

        return [
            'people' => $page,
            'summaries' => $summaries,
            'accessOf' => $access,
            'headers' => [
                ['key' => 'username', 'label' => 'Usuario'],
                ['key' => 'name', 'label' => 'Persona', 'class' => 'min-w-36 min-[1200px]:min-w-52'],
                ['key' => 'roles', 'label' => 'Puestos vigentes', 'sortable' => false],
                ['key' => 'is_active', 'label' => 'Estado', 'sortable' => false],
                ['key' => 'access', 'label' => 'Acceso', 'sortable' => false],
            ],
            'rowDecoration' => ['text-neutral' => fn (User $user): bool => ! $user->is_active],
            'roles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->listLabel()], Role::cases()),
            'statuses' => [['id' => 'active', 'name' => 'Activos'], ['id' => 'inactive', 'name' => 'Inactivos']],
            'accesses' => array_map(fn (UserAccess $access): array => ['id' => $access->value, 'name' => $access->label()], UserAccess::cases()),
            'canCreate' => Gate::allows('create', User::class),
        ];
    }

    public function openCreate(): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
        $this->authorize('create', User::class);

        $this->reset('newUsername', 'newName', 'newEmail');
        $this->resetValidation();
        $this->showCreate = true;
    }

    /**
     * «Dar de alta» (CF-S3): el usuario se guarda como llega, sin los espacios de los extremos; el correo, en minúsculas y
     * del dominio institucional, único sin importar mayúsculas (UserAccountRules). Nace activo y sin acceso
     * (CreateStaffUser); con correo, después del commit le llega la invitación (InviteUser,
     * CF-07) si quien la da de alta puede invitar (UserPolicy, `invite`), y la pantalla lleva a su ficha para nombrarlo.
     * Los errores de las reglas y de la Action salen junto a su campo; si la invitación no sale, el alta queda y el aviso
     * dice por qué.
     */
    public function createUser(CreateStaffUser $createStaffUser, InviteUser $inviteUser, UserAccountRules $rules): void
    {
        $this->authorize('viewAny', RoleAssignment::class);
        $this->authorize('create', User::class);

        $this->newUsername = trim($this->newUsername);
        $this->newName = trim($this->newName);

        $this->validate([
            'newUsername' => ['required', 'string', 'max:255'],
            'newName' => ['required', 'string', 'max:160'],
            'newEmail' => ['nullable', 'string', 'max:255'],
        ], [
            'newUsername.required' => 'Escribe el usuario.',
            'newUsername.max' => 'El usuario no puede pasar de 255 caracteres.',
            'newName.required' => 'Escribe el nombre.',
            'newName.max' => 'El nombre no puede pasar de 160 caracteres.',
            'newEmail.max' => 'El correo no puede pasar de 255 caracteres.',
        ]);

        try {
            $email = $rules->institutionalEmail($this->newEmail);

            if ($email !== null) {
                $rules->ensureEmailIsFree($email);
            }

            $user = $createStaffUser->handle($this->newUsername, $this->newName, email: $email);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [
                match ($key) {
                    'username' => 'newUsername',
                    'email' => 'newEmail',
                    default => 'newName',
                } => $messages,
            ])->all());
        }

        $this->showCreate = false;
        $redirectTo = route('core.people.show', $user->id);

        if ($user->email === null) {
            $this->success('Usuario dado de alta', e("{$user->username} queda sin acceso hasta que captures su correo institucional. Nómbralo en su ficha."), redirectTo: $redirectTo);

            return;
        }

        if (Gate::denies('invite', $user)) {
            $this->success('Usuario dado de alta', e("{$user->username} queda sin acceso: no puedes mandarle la invitación. Nómbralo en su ficha."), redirectTo: $redirectTo);

            return;
        }

        try {
            $email = $inviteUser->handle($user, auth()->user());
        } catch (ValidationException $exception) {
            $this->warning('Usuario dado de alta', e("{$user->username} queda sin acceso: la invitación no salió (".collect($exception->errors())->flatten()->first().'). Mándala desde su ficha.'), redirectTo: $redirectTo);

            return;
        }

        $this->success('Usuario dado de alta', e("Invitación enviada a {$email}. Nómbralo en su ficha."), redirectTo: $redirectTo);
    }

    public function showTab(string $tab): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        $this->tab = $tab === 'appointments' ? 'appointments' : 'people';
    }

    /**
     * @return array<string, mixed>
     */
    private function appointmentsData(PeopleQuery $people, AssignmentLabels $labels, AppointmentsQuery $appointments): array
    {
        $page = $appointments->forTable(
            $this->appSearch,
            Role::tryFrom($this->appRole),
            $this->appModality === '' ? null : $this->appModality,
            $this->appCampus === '' ? null : $this->appCampus,
            $this->appCurrent !== 'all',
        );
        $described = $labels->describe($page->getCollection(), withCareerNames: true);
        $options = $people->scopeOptions(Role::Coordinador, null);
        $rows = [];

        foreach ($page as $assignment) {
            $label = $described[$assignment->getKey()];
            $day = today()->toDateString();
            $rows[$assignment->getKey()] = [
                'careerCode' => $label->careerCode,
                'careerName' => $label->careerName,
                'unit' => $label->unitName,
                'modality' => $label->modalityName ?? 'Todas',
                'campus' => $label->campusName ?? '—',
                'state' => $assignment->valid_from->toDateString() > $day ? 'future' : ($assignment->valid_to !== null && $assignment->valid_to->toDateString() < $day ? 'ended' : 'current'),
            ];
        }

        return [
            'appointments' => $page,
            'rows' => $rows,
            'titles' => collect($described)->map(fn ($label) => $label->title)->all(),
            'roles' => array_map(fn (Role $role): array => ['id' => $role->value, 'name' => $role->listLabel()], array_filter(Role::cases(), fn (Role $role): bool => $role !== Role::Docente && $role !== Role::Alumno)),
            'modalities' => $options['modalities'],
            'campuses' => [['id' => AppointmentsQuery::ALL_CAMPUSES, 'name' => 'Todos los campus'], ...$options['campuses']],
            'currentOptions' => [['id' => 'current', 'name' => 'Vigentes'], ['id' => 'all', 'name' => 'Todos']],
            'headers' => [
                ['key' => 'person', 'label' => 'Persona', 'sortable' => false, 'class' => 'min-w-36'],
                ['key' => 'role', 'label' => 'Puesto', 'sortable' => false],
                ['key' => 'scope', 'label' => 'Carrera o departamento', 'sortable' => false],
                ['key' => 'modality', 'label' => 'Modalidad', 'sortable' => false],
                ['key' => 'campus', 'label' => 'Campus', 'sortable' => false],
                ['key' => 'validity', 'label' => 'Vigencia', 'sortable' => false],
            ],
        ];
    }

    public function updated(string $property): void
    {
        $this->authorize('viewAny', RoleAssignment::class);

        if (in_array($property, ['appSearch', 'appRole', 'appModality', 'appCampus', 'appCurrent'], true)) {
            $this->resetPage('appointmentsPage');
        }

        if (in_array($property, ['search', 'role', 'status', 'access'], true)) {
            $this->resetPage();
        }
    }
};
