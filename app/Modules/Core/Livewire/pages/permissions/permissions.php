<?php

use App\Modules\Core\Actions\ApplyFactoryUpdates;
use App\Modules\Core\Actions\AssignPermissionSet;
use App\Modules\Core\Actions\CreatePermissionSet;
use App\Modules\Core\Actions\DeletePermissionSet;
use App\Modules\Core\Actions\GrantPositionPermission;
use App\Modules\Core\Actions\RestoreFactoryPermissionSet;
use App\Modules\Core\Actions\RestoreFactoryPosition;
use App\Modules\Core\Actions\RevokePositionPermission;
use App\Modules\Core\Actions\SetPermissionSetActive;
use App\Modules\Core\Actions\UnassignPermissionSet;
use App\Modules\Core\Actions\UpdatePermissionSet;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\PermissionSet;
use App\Modules\Core\Queries\PermissionCatalogQuery;
use App\Modules\Core\Queries\PermissionSetsQuery;
use App\Modules\Core\Queries\PositionsQuery;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Locked;
use Livewire\Attributes\Session;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Mary\Traits\Toast;

/**
 * «Puestos y permisos» (tarea 1.22-B; maqueta B «por puesto», elegida por el usuario): qué puede hacer cada puesto con
 * sus roles y sus permisos sueltos (CF-01). Tres pestañas:
 *
 * - **Puestos:** a la izquierda las tarjetas de los siete puestos; a la derecha el elegido (`?puesto=`), con sus roles,
 *   sus permisos sueltos y sus permisos efectivos por grupo con su origen. Administración sale de solo lectura: todo
 *   menos sus excepciones, que se listan (CF-S1).
 * - **Roles:** la tabla de roles con la barra de catálogo, «Nuevo rol», «Editar» y el menú «Acciones».
 * - **¿Quién puede…?:** un permiso (`?permiso=`) con los puestos que lo tienen y por qué, y por qué no lo tienen los
 *   demás.
 *
 * Los drawers «Agregar rol», «Agregar permiso suelto» y «Editar rol» muestran deshabilitado, con el motivo de las
 * reglas, lo que no se puede dar (CF-03, CF-04, CF-S2); las confirmaciones cortas van con `x-confirm` (ADR-0023). Cada
 * cambio llama a la Action del motor, que revisa las reglas otra vez; sus errores salen junto al campo, en el drawer,
 * o en el aviso.
 *
 * Lee con PositionsQuery, PermissionSetsQuery y PermissionCatalogQuery. Autoriza con PermissionSetPolicy en mount(),
 * en with() y en cada método público: `viewAny` para ver, `managePositions` para lo de los puestos y `create`,
 * `update` o `delete` para los roles. Solo el admin las tiene (core.authz.*, candados).
 */
new #[Title('Puestos y permisos')] class extends Component
{
    use Toast;

    /** '' (Puestos), `roles` o `quien`. */
    #[Url(as: 'vista', except: '')]
    public string $tab = '';

    /** El valor del puesto elegido; '' es el primero que se edita. */
    #[Url(as: 'puesto', except: '')]
    public string $position = '';

    /** La clave del permiso elegido en «¿Quién puede…?»; '' es el primero de la lista. */
    #[Url(as: 'permiso', except: '')]
    public string $permissionKey = '';

    public string $effectiveSearch = '';

    /** «Fuera de fábrica»: solo lo que no le llega por su rol de fábrica. */
    public bool $outsideFactory = false;

    /** Búsqueda de la tabla de roles; se recuerda en la sesión del usuario (DESIGN.md). */
    #[Session]
    public string $rolesSearch = '';

    /** '' (todos), `factory` u `own`; se recuerda en la sesión del usuario. */
    #[Session]
    public string $rolesKind = '';

    public string $whoSearch = '';

    /** `showAddRole`, `showAddLoose` y `showEditor` no pueden ser #[Locked]: el x-drawer los cierra desde el navegador. */
    public bool $showAddRole = false;

    public string $addRoleId = '';

    public bool $showAddLoose = false;

    /** @var list<string> */
    public array $looseKeys = [];

    public bool $showEditor = false;

    /** El rol que se edita; null es un alta. El navegador no lo puede cambiar. */
    #[Locked]
    public ?string $editingSetId = null;

    public string $name = '';

    public string $description = '';

    /** @var list<string> */
    public array $permissions = [];

    /** Búsqueda y «Todos / Marcados» de la lista de permisos de los drawers. */
    public string $pickerSearch = '';

    public bool $pickerMarked = false;

    public function mount(): void
    {
        $this->authorize('viewAny', PermissionSet::class);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(PositionsQuery $positions, PermissionSetsQuery $sets, PermissionCatalogQuery $catalog): array
    {
        $this->authorize('viewAny', PermissionSet::class);

        $people = $positions->peopleByRoleSet();
        $positionRows = $positions->all($people);
        $setRows = $sets->all();
        $catalogRows = $catalog->all($positionRows, $people);
        $setsById = array_column($setRows, null, 'id');
        $catalogByKey = array_column($catalogRows, null, 'key');
        $role = $this->role();
        $current = collect($positionRows)->firstWhere('role', $role);
        $view = in_array($this->tab, ['roles', 'quien'], true) ? $this->tab : '';

        return [
            'view' => $view,
            'cards' => array_map(fn (array $row): array => $this->card($row, $setsById, $role), $positionRows),
            'current' => $view === '' ? $this->detail($current, $setsById, $catalogByKey) : null,
            'positionCount' => count($positionRows),
            'setCount' => count($setRows),
            'roleRows' => $view === 'roles' ? array_map(fn (array $set): array => $this->roleRow($set), $sets->forTable($this->rolesSearch, $this->rolesKind, $setRows)) : [],
            'kinds' => [['id' => 'factory', 'name' => 'De fábrica'], ['id' => 'own', 'name' => 'Propios']],
            'who' => $view === 'quien' ? $this->who($catalog, $catalogRows, $positionRows) : null,
            'addRole' => $this->showAddRole ? $this->addRoleData($role, $current, $setRows, $sets, $catalogRows) : null,
            'addLoose' => $this->showAddLoose ? $this->addLooseData($role, $current, $catalogRows, $catalog) : null,
            'editor' => $this->showEditor ? $this->editorData($setRows, $sets, $catalogRows, $positionRows) : null,
            'canManage' => Gate::allows('managePositions', PermissionSet::class),
        ];
    }

    public function updatedPosition(): void
    {
        $this->reset('effectiveSearch', 'outsideFactory');
    }

    public function openAddRole(): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $this->closeDrawers();
        $this->showAddRole = true;
    }

    public function assignRole(PermissionSetsQuery $sets, AssignPermissionSet $assign): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        if ($this->addRoleId === '') {
            $this->addError('addRoleId', 'Elige un rol.');

            return;
        }

        $role = $this->role();
        $set = $sets->findOrFail($this->addRoleId);
        $assign->handle($role, $set);

        $this->showAddRole = false;
        $this->success("{$role->listLabel()} tiene el rol «{$set->name}»", 'Lo tienen desde su siguiente acción.');
    }

    public function unassignRole(string $setId, PermissionSetsQuery $sets, UnassignPermissionSet $unassign): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $role = $this->role();
        $set = $sets->findOrFail($setId);

        try {
            $unassign->handle($role, $set);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se quitó el rol');

            return;
        }

        $this->success('Rol quitado', e("«{$set->name}» · {$role->listLabel()}"));
    }

    public function openAddLoose(): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $this->closeDrawers();
        $this->showAddLoose = true;
    }

    /**
     * Da los permisos sueltos marcados. Revisa todos antes de dar el primero (CF-S1, CF-03, CF-04), para no dar solo una
     * parte; cada uno pasa además por GrantPositionPermission, que revisa las reglas otra vez.
     */
    public function grantLoose(GrantPositionPermission $grant, PermissionCatalogQuery $catalog): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $role = $this->role();
        $keys = array_values(array_unique(array_map('strval', $this->looseKeys)));

        if ($keys === []) {
            $this->addError('looseKeys', 'Elige al menos un permiso.');

            return;
        }

        $problems = $catalog->grantProblems($role);
        $found = array_values(array_unique(array_filter(array_map(
            fn (string $key): ?string => array_key_exists($key, $problems) ? $problems[$key] : "El permiso «{$key}» no existe.",
            $keys,
        ))));

        if ($found !== []) {
            throw ValidationException::withMessages(['looseKeys' => $found]);
        }

        foreach ($keys as $key) {
            $grant->handle($role, $catalog->find($key));
        }

        $this->showAddLoose = false;
        $this->success($this->plural(count($keys), 'permiso suelto', 'permisos sueltos')." para {$role->listLabel()}", 'Lo tienen desde su siguiente acción.');
    }

    public function revokeLoose(string $key, PermissionCatalogQuery $catalog, RevokePositionPermission $revoke): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $role = $this->role();
        $permission = $catalog->find($key);

        try {
            $permission ?? throw ValidationException::withMessages(['permission' => "El permiso «{$key}» no existe."]);
            $revoke->handle($role, $permission);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se quitó el permiso');

            return;
        }

        $this->success('Permiso suelto quitado', e("«{$permission->label()}» · {$role->listLabel()}"));
    }

    public function restorePosition(RestoreFactoryPosition $restore): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $role = $this->role();

        try {
            $restore->handle($role);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se restableció el puesto');

            return;
        }

        $this->success('Puesto restablecido', e("{$role->listLabel()} se queda solo con su rol de fábrica."));
    }

    /**
     * «¿Quién puede…?» → «Dar»: el permiso a ese puesto, como suelto.
     */
    public function grantFromWho(string $position, string $key, PermissionCatalogQuery $catalog, GrantPositionPermission $grant): void
    {
        $this->authorize('managePositions', PermissionSet::class);

        $role = Role::tryFrom($position);
        $permission = $catalog->find($key);

        try {
            $role ?? throw ValidationException::withMessages(['role' => 'Ese puesto no existe.']);
            $permission ?? throw ValidationException::withMessages(['permission' => "El permiso «{$key}» no existe."]);
            $grant->handle($role, $permission);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se dio el permiso');

            return;
        }

        $this->success("{$role->listLabel()} ya puede «{$permission->label()}»", 'Como permiso suelto.');
    }

    public function openNewRole(): void
    {
        $this->authorize('create', PermissionSet::class);

        $this->closeDrawers();
        $this->showEditor = true;
    }

    public function editRole(string $setId, PermissionSetsQuery $sets): void
    {
        $set = $sets->findOrFail($setId);
        $this->authorize('update', $set);

        $this->closeDrawers();
        $this->editingSetId = $set->id;
        $this->name = $set->name;
        $this->description = $set->description ?? '';
        $this->permissions = $this->keysOf($set->id, $sets);
        $this->pickerMarked = $this->permissions !== [];
        $this->showEditor = true;
    }

    public function saveRole(CreatePermissionSet $create, UpdatePermissionSet $update, PermissionSetsQuery $sets): void
    {
        $set = $this->editingSetId === null ? null : $sets->findOrFail($this->editingSetId);
        $this->authorize($set === null ? 'create' : 'update', $set ?? PermissionSet::class);

        $this->validate([
            'name' => ['string'],
            'description' => ['string'],
            'permissions' => ['array'],
            'permissions.*' => ['string'],
        ]);

        if ($set === null) {
            $set = $create->handle($this->name, $this->description, $this->permissions);
            $this->success('Rol creado', e("«{$set->name}» con ".$this->plural(count($this->permissions), 'permiso', 'permisos').'. Dáselo a un puesto.'));
        } else {
            $set = $update->handle($set, ['name' => $this->name, 'description' => $this->description, 'permissions' => $this->permissions]);
            $this->success('Rol guardado', e("«{$set->name}» vale desde la siguiente acción de cada usuario."));
        }

        $this->showEditor = false;
    }

    public function restoreRole(string $setId, PermissionSetsQuery $sets, RestoreFactoryPermissionSet $restore): void
    {
        $set = $sets->findOrFail($setId);
        $this->authorize('update', $set);

        try {
            $restore->handle($set);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se restableció el rol');

            return;
        }

        $this->reloadEditor($set->id, $sets);
        $this->success('Rol restablecido', e("«{$set->name}» vuelve a seguir al código."));
    }

    public function applyFactoryUpdates(string $setId, PermissionSetsQuery $sets, ApplyFactoryUpdates $apply): void
    {
        $set = $sets->findOrFail($setId);
        $this->authorize('update', $set);

        try {
            $apply->handle($set);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se aplicaron los permisos nuevos');

            return;
        }

        $this->reloadEditor($set->id, $sets);
        $this->success('Permisos nuevos aplicados', e("«{$set->name}»"));
    }

    public function setRoleActive(string $setId, bool $active, PermissionSetsQuery $sets, SetPermissionSetActive $setActive): void
    {
        $set = $sets->findOrFail($setId);
        $this->authorize('update', $set);

        try {
            $setActive->handle($set, $active);
        } catch (ValidationException $exception) {
            $this->report($exception, $active ? 'No se activó el rol' : 'No se desactivó el rol');

            return;
        }

        $this->success($active ? 'Rol activado' : 'Rol desactivado', e("«{$set->name}»"));
    }

    public function deleteRole(string $setId, PermissionSetsQuery $sets, DeletePermissionSet $delete): void
    {
        $set = $sets->findOrFail($setId);
        $this->authorize('delete', $set);

        try {
            $delete->handle($set);
        } catch (ValidationException $exception) {
            $this->report($exception, 'No se borró el rol');

            return;
        }

        $this->success('Rol borrado', e("«{$set->name}»"));
    }

    /**
     * Marca o desmarca de un jalón los permisos que se ven y se pueden marcar de un grupo, en `loose` (Agregar permiso
     * suelto) o en `editor` (Editar rol): si ya están marcados todos los del grupo que se pueden marcar, desmarca los que
     * se ven; si no, marca los que se ven.
     */
    public function toggleGroup(string $target, string $group, PositionsQuery $positions, PermissionSetsQuery $sets, PermissionCatalogQuery $catalog): void
    {
        if ($target === 'editor') {
            $set = $this->editingSetId === null ? null : $sets->findOrFail($this->editingSetId);
            $this->authorize($set === null ? 'create' : 'update', $set ?? PermissionSet::class);
        } else {
            $this->authorize('managePositions', PermissionSet::class);
        }

        $people = $positions->peopleByRoleSet();
        $positionRows = $positions->all($people);
        $catalogRows = $catalog->all($positionRows, $people);

        $rows = match ($target) {
            'loose' => $this->looseRows($this->role(), collect($positionRows)->firstWhere('role', $this->role()), $catalogRows, $catalog),
            'editor' => $this->editorRows($sets->all(), $sets, $catalogRows),
            default => [],
        };
        $enabled = array_filter($rows, fn (array $row): bool => $row['group'] === $group && ! $row['disabled']);
        $keys = array_column(array_filter($enabled, fn (array $row): bool => $this->isVisible($row)), 'key');
        $property = $target === 'editor' ? 'permissions' : 'looseKeys';
        $selected = $this->{$property};

        $this->{$property} = array_values(array_diff(array_column($enabled, 'key'), $selected) === []
            ? array_diff($selected, $keys)
            : array_unique([...$selected, ...$keys]));
    }

    /**
     * El puesto elegido; si la URL no trae uno, la Subdirección, el primero que se edita.
     */
    private function role(): Role
    {
        return Role::tryFrom($this->position) ?? Role::Subdireccion;
    }

    private function closeDrawers(): void
    {
        $this->resetValidation();
        $this->reset('showAddRole', 'addRoleId', 'showAddLoose', 'looseKeys', 'showEditor', 'editingSetId', 'name', 'description', 'permissions', 'pickerSearch', 'pickerMarked');
    }

    /**
     * Si el editor está abierto en ese rol, vuelve a leer sus permisos (después de restablecerlo o de aplicar lo nuevo).
     */
    private function reloadEditor(string $setId, PermissionSetsQuery $sets): void
    {
        if ($this->showEditor && $this->editingSetId === $setId) {
            $this->permissions = $this->keysOf($setId, $sets);
        }
    }

    /**
     * Las claves que aporta hoy el rol (las de fábrica si sigue al código).
     *
     * @return list<string>
     */
    private function keysOf(string $setId, PermissionSetsQuery $sets): array
    {
        $row = collect($sets->all())->firstWhere('id', $setId);

        return array_column(array_merge(...array_values($row['permissions'] ?? [])), 'key');
    }

    private function report(ValidationException $exception, string $title): void
    {
        foreach ($exception->errors() as $field => $messages) {
            $this->addError($field, $messages[0]);
        }

        $this->error($title, e(collect($exception->errors())->flatten()->first()));
    }

    /**
     * @param  array<string, mixed>  $row  un renglón de PositionsQuery::all()
     * @param  array<string, array<string, mixed>>  $setsById
     * @return array<string, mixed>
     */
    private function card(array $row, array $setsById, Role $selected): array
    {
        return [
            'value' => $row['role']->value,
            'label' => $row['label'],
            'holders' => $row['holders'],
            'selected' => $row['role'] === $selected,
            'summary' => $row['locked'] !== null
                ? 'Todo menos '.$this->plural(count($row['admin_denies']), 'excepción', 'excepciones')
                : implode(' · ', [
                    $this->plural(count($row['permission_sets']), 'rol', 'roles'),
                    $this->plural(count($row['permissions']), 'suelto', 'sueltos'),
                    $this->plural(count($row['effective']), 'permiso', 'permisos'),
                ]),
            'new' => count($this->newFactoryOf($row, $setsById)['keys'] ?? []),
        ];
    }

    /**
     * El rol de fábrica del puesto, si lo tiene, con lo nuevo de fábrica que no se ha aplicado (CF-02).
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $setsById
     * @return array{id: string, keys: list<string>}|null
     */
    private function newFactoryOf(array $row, array $setsById): ?array
    {
        $own = collect($row['permission_sets'])->firstWhere('factory_role', $row['role']);

        return $own === null ? null : ['id' => $own['id'], 'keys' => $setsById[$own['id']]['new_factory'] ?? []];
    }

    /**
     * El puesto elegido, para el panel de la derecha.
     *
     * @param  array<string, mixed>  $row
     * @param  array<string, array<string, mixed>>  $setsById
     * @param  array<string, array<string, mixed>>  $catalogByKey
     * @return array<string, mixed>
     */
    private function detail(array $row, array $setsById, array $catalogByKey): array
    {
        $role = $row['role'];
        $label = fn (string $key): string => $catalogByKey[$key]['label'] ?? $key;
        $needle = $this->folded($this->effectiveSearch);
        $filtering = $needle !== '' || $this->outsideFactory;
        $effective = array_map(fn (array $permission): array => [
            ...$permission,
            'group' => $catalogByKey[$permission['key']]['group'] ?? PermissionCatalogQuery::groupOf($permission['key']),
            'group_label' => $catalogByKey[$permission['key']]['group_label'] ?? $permission['module'],
            'chips' => $this->chips($permission['sources']),
        ], $row['effective']);
        $shown = array_values(array_filter($effective, fn (array $permission): bool => ($needle === '' || str_contains($this->folded($permission['label'].' '.$permission['key']), $needle))
            && (! $this->outsideFactory || array_any($permission['sources'], fn (array $source): bool => ! in_array($source['kind'], ['factory', 'admin'], true)))));
        $openAll = $filtering || ($row['locked'] === null && count($effective) < 20);
        $groups = PermissionCatalogQuery::grouped($shown);
        $groups = array_map(fn (array $group, int $index): array => [...$group, 'open' => $openAll || $index < 2], $groups, array_keys($groups));
        $new = $this->newFactoryOf($row, $setsById);

        return [
            'value' => $role->value,
            'label' => $row['label'],
            'scope' => $row['scope'],
            'holders' => $row['holders'],
            'locked' => $row['locked'] !== null,
            'denies' => array_map(fn (string $key): array => ['key' => $key, 'label' => $label($key)], $row['admin_denies']),
            'declared' => count($catalogByKey),
            'effectiveCount' => count($effective),
            'sets' => array_map(function (array $set) use ($role, $setsById): array {
                $info = $setsById[$set['id']] ?? null;

                return [
                    'id' => $set['id'],
                    'name' => $set['name'],
                    'is_active' => $set['is_active'],
                    'count' => $info['permission_count'] ?? 0,
                    'others' => array_values(array_map(fn (Role $holder): string => $holder->listLabel(), array_filter($info['positions'] ?? [], fn (Role $holder): bool => $holder !== $role))),
                    'badge' => $info !== null && ($set['factory_role'] === $role || ! $set['is_active']) ? $this->badge($info) : null,
                ];
            }, $this->ownFactoryFirst($row['permission_sets'], $role)),
            'loose' => array_map(fn (string $key): array => ['key' => $key, 'label' => $label($key)], $row['permissions']),
            'newFactory' => $new === null || $new['keys'] === [] ? null : ['id' => $new['id'], 'labels' => array_map($label, $new['keys'])],
            'groups' => $groups,
            'filtering' => $filtering,
            'listKey' => md5($role->value.'|'.$needle.'|'.($this->outsideFactory ? 1 : 0)),
        ];
    }

    /**
     * Los roles del puesto con su rol de fábrica primero; los demás, en el orden de PositionsQuery (por nombre).
     *
     * @param  list<array<string, mixed>>  $sets
     * @return list<array<string, mixed>>
     */
    private function ownFactoryFirst(array $sets, Role $role): array
    {
        usort($sets, fn (array $a, array $b): int => ($b['factory_role'] === $role) <=> ($a['factory_role'] === $role));

        return $sets;
    }

    /**
     * Las insignias de origen de un permiso efectivo.
     *
     * @param  list<array{kind: string, name: string|null}>  $sources
     * @return list<array{kind: string, label: string, icon: string, title: string|null}>
     */
    private function chips(array $sources): array
    {
        return array_map(fn (array $source): array => match ($source['kind']) {
            'admin' => ['kind' => 'admin', 'label' => 'Todo', 'icon' => 'o-check', 'title' => 'Todo menos sus excepciones'],
            'factory' => ['kind' => 'factory', 'label' => 'Fábrica', 'icon' => 'o-check-circle', 'title' => $source['name']],
            'loose' => ['kind' => 'loose', 'label' => 'Suelto', 'icon' => 'o-plus-circle', 'title' => 'Permiso suelto'],
            default => ['kind' => 'role', 'label' => (string) $source['name'], 'icon' => 'o-rectangle-stack', 'title' => 'Rol «'.$source['name'].'»'],
        }, $sources);
    }

    /**
     * El origen en texto, para «Ya lo tiene: …».
     *
     * @param  array{kind: string, name: string|null}  $source
     */
    private function sourceLabel(array $source): string
    {
        return match ($source['kind']) {
            'admin' => 'todo menos sus excepciones',
            'factory' => 'Rol de fábrica',
            'loose' => 'Permiso suelto',
            default => 'Rol «'.$source['name'].'»',
        };
    }

    /**
     * La insignia de estado de un rol (CF-02, CF-S2).
     *
     * @param  array<string, mixed>  $set  un renglón de PermissionSetsQuery::all()
     * @return array{tone: string, label: string, icon: string|null}
     */
    private function badge(array $set): array
    {
        $new = count($set['new_factory']);
        $factory = $set['factory_role'] !== null;

        return match (true) {
            ! $set['is_active'] => ['tone' => 'neutral', 'label' => 'Inactivo', 'icon' => null],
            $factory && $set['follows_factory'] => ['tone' => 'success', 'label' => 'Sigue al código', 'icon' => null],
            $factory && $new > 0 => ['tone' => 'warning', 'label' => $this->plural($new, 'nuevo de fábrica', 'nuevos de fábrica'), 'icon' => 'o-sparkles'],
            $factory => ['tone' => 'info', 'label' => 'Editado', 'icon' => 'o-pencil-square'],
            default => ['tone' => 'success', 'label' => 'Activo', 'icon' => null],
        };
    }

    /**
     * Un renglón de la tabla de roles, con los motivos de lo que su menú no deja hacer (CF-02, CF-S2).
     *
     * @param  array<string, mixed>  $set
     * @return array<string, mixed>
     */
    private function roleRow(array $set): array
    {
        $factory = $set['factory_role'] !== null;
        $assigned = count($set['positions']);

        return [
            'id' => $set['id'],
            'name' => $set['name'],
            'description' => $set['description'],
            'is_active' => $set['is_active'],
            'count' => $set['permission_count'],
            'positions' => array_map(fn (Role $role): string => $role->listLabel(), $set['positions']),
            'badge' => $this->badge($set),
            'newCount' => count($set['new_factory']),
            'restoreReason' => ! $factory ? 'Solo los roles de fábrica se restablecen.' : ($set['follows_factory'] ? 'Ya sigue al código.' : null),
            'toggleReason' => $factory ? 'Los roles de fábrica no se desactivan.' : null,
            'deleteReason' => match (true) {
                $factory => 'Los roles de fábrica no se borran.',
                $assigned === 1 => 'Lo tiene un puesto: quítaselo primero.',
                $assigned > 1 => "Lo tienen {$assigned} puestos: quítaselo primero.",
                default => null,
            },
            'assigned' => $assigned,
        ];
    }

    /**
     * «¿Quién puede…?»: la lista de permisos que deja la búsqueda y el elegido, con quién lo tiene y por qué, y por qué
     * no lo tienen los demás; a quien se le puede dar y le valdría en todo el Tec, se le avisa (15 de noviembre de 2026).
     *
     * @param  list<array<string, mixed>>  $catalogRows
     * @param  list<array<string, mixed>>  $positionRows
     * @return array<string, mixed>
     */
    private function who(PermissionCatalogQuery $catalog, array $catalogRows, array $positionRows): array
    {
        $needle = $this->folded($this->whoSearch);
        $list = array_values(array_filter($catalogRows, fn (array $row): bool => $needle === '' || str_contains($this->folded($row['label'].' '.$row['key']), $needle)));
        $selected = collect($catalogRows)->firstWhere('key', $this->permissionKey) ?? ($list[0] ?? null);
        $result = null;

        if ($selected !== null) {
            $problems = $catalog->problemsFor($selected['key']);
            $holders = collect($selected['positions'])->keyBy(fn (array $holder): string => $holder['role']->value);
            $result = [...$selected, 'has' => [], 'missing' => []];

            foreach ($positionRows as $row) {
                $value = $row['role']->value;

                if ($holders->has($value)) {
                    $result['has'][] = ['value' => $value, 'label' => $row['label'], 'holders' => $row['holders'], 'chips' => $this->chips($holders[$value]['sources'])];
                } else {
                    $result['missing'][] = [
                        'value' => $value,
                        'label' => $row['label'],
                        'problem' => $problems[$value] ?? null,
                        'wide' => in_array($row['role'], $selected['institution_wide_for'], true),
                    ];
                }
            }
        }

        return [
            'list' => array_map(fn (array $row): array => ['key' => $row['key'], 'label' => $row['label']], $list),
            'total' => count($list),
            'selected' => $result,
        ];
    }

    /**
     * «Agregar rol»: los roles, primero los que se pueden dar, luego los que ya tiene y al final los bloqueados con su
     * motivo (CF-03, CF-04). Uno desactivado se puede dar, pero no aporta hasta que se active (CF-S2). Cada uno dice qué
     * de lo que trae, y el puesto no tiene, le valdría en todo el Tec (15 de noviembre de 2026).
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $setRows
     * @param  list<array<string, mixed>>  $catalogRows
     * @return array<string, mixed>
     */
    private function addRoleData(Role $role, array $current, array $setRows, PermissionSetsQuery $sets, array $catalogRows): array
    {
        $problems = $sets->assignProblems($role, $setRows);
        $mine = array_column($current['permission_sets'], 'id');
        $rank = ['free' => 0, 'mine' => 1, 'blocked' => 2];
        $wide = $this->wideFor($role, $current, $catalogRows);
        $options = array_map(function (array $set) use ($problems, $mine, $wide): array {
            $found = $problems[$set['id']] ?? [];
            $keys = array_column(array_merge(...array_values($set['permissions'])), 'key');

            return [
                'id' => $set['id'],
                'name' => $set['name'],
                'count' => $set['permission_count'],
                'positions' => array_map(fn (Role $holder): string => $holder->listLabel(), $set['positions']),
                'is_active' => $set['is_active'],
                'state' => in_array($set['id'], $mine, true) ? 'mine' : ($found !== [] ? 'blocked' : 'free'),
                'problem' => $found[0] ?? null,
                'more' => max(0, count($found) - 1),
                'wide' => $this->wideText(array_values(array_intersect_key($wide, array_flip($keys)))),
            ];
        }, $setRows);
        usort($options, fn (array $a, array $b): int => $rank[$a['state']] <=> $rank[$b['state']]);

        return ['label' => $current['label'], 'holders' => $current['holders'], 'options' => $options];
    }

    /**
     * «Agregar permiso suelto»: cada permiso declarado, con lo que ya tiene marcado y deshabilitado, lo que no se le
     * puede dar deshabilitado con su motivo, y «Valdrá en todo el Tec» en lo que le valdría sin límite (15 de noviembre
     * de 2026).
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $catalogRows
     * @return array<string, mixed>
     */
    private function addLooseData(Role $role, array $current, array $catalogRows, PermissionCatalogQuery $catalog): array
    {
        $rows = $this->looseRows($role, $current, $catalogRows, $catalog);
        $free = array_filter($rows, fn (array $row): bool => ! $row['disabled']);
        $firstOpen = array_slice(array_values(array_unique(array_column($free, 'group'))), 0, $role->boundedDimensions() === [] ? 1 : 2);

        return [
            'label' => $current['label'],
            'holders' => $current['holders'],
            'noScope' => $role->boundedDimensions() === [],
            'groups' => $this->pickerGroups($rows, $firstOpen, fn (array $group): string => ($n = count(array_filter($group, fn (array $row): bool => ! $row['disabled']))) > 0 ? "{$n} se pueden dar" : '—'),
            'remaining' => count($free),
            'selected' => count(array_intersect($this->looseKeys, array_column($free, 'key'))),
        ];
    }

    /**
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $catalogRows
     * @return list<array<string, mixed>>
     */
    private function looseRows(Role $role, array $current, array $catalogRows, PermissionCatalogQuery $catalog): array
    {
        $problems = $catalog->grantProblems($role);
        $effective = array_column($current['effective'], null, 'key');
        $noScope = $role->boundedDimensions() === [];
        $wide = $this->wideFor($role, $current, $catalogRows);

        return array_map(function (array $permission) use ($problems, $effective, $noScope, $wide): array {
            $had = $effective[$permission['key']] ?? null;
            $problem = $had === null ? ($problems[$permission['key']] ?? null) : null;

            return [
                'key' => $permission['key'],
                'label' => $permission['label'],
                'group' => $permission['group'],
                'group_label' => $permission['group_label'],
                'checked' => $had !== null || in_array($permission['key'], $this->looseKeys, true),
                'disabled' => $had !== null || $problem !== null,
                'problem' => $noScope && ! $permission['admin_only'] ? null : $problem,
                'locked' => $permission['admin_only'],
                'note' => $had === null ? null : 'Ya lo tiene: '.implode(', ', array_map(fn (array $source): string => $this->sourceLabel($source), $had['sources'])),
                'wide' => isset($wide[$permission['key']]) ? 'Valdrá en todo el Tec.' : null,
            ];
        }, $catalogRows);
    }

    /**
     * «Valdrá en todo el Tec: «A», «B», «C» y N más.», o null si no hay nada.
     *
     * @param  list<string>  $labels
     */
    private function wideText(array $labels): ?string
    {
        if ($labels === []) {
            return null;
        }

        $more = count($labels) - 3;

        return 'Valdrá en todo el Tec: «'.implode('», «', array_slice($labels, 0, 3)).'»'.($more > 0 ? " y {$more} más" : '').'.';
    }

    /**
     * Lo que el puesto no tiene hoy, se le puede dar y le valdría en todo el Tec (`institution_wide_for`; confirmado el
     * 15 de noviembre de 2026: se puede dar, pero se avisa).
     *
     * @param  array<string, mixed>  $current
     * @param  list<array<string, mixed>>  $catalogRows
     * @return array<string, string> etiqueta por clave
     */
    private function wideFor(Role $role, array $current, array $catalogRows): array
    {
        $had = array_column($current['effective'], 'key', 'key');
        $wide = [];

        foreach ($catalogRows as $permission) {
            if (! isset($had[$permission['key']]) && in_array($role, $permission['institution_wide_for'], true)) {
                $wide[$permission['key']] = $permission['label'];
            }
        }

        return $wide;
    }

    /**
     * «Editar rol» o «Nuevo rol»: sus datos, quién lo tiene, sus avisos de fábrica (CF-02) y la lista de permisos, con
     * lo que no se le puede agregar deshabilitado con su motivo (CF-03, CF-S2).
     *
     * @param  list<array<string, mixed>>  $setRows
     * @param  list<array<string, mixed>>  $catalogRows
     * @param  list<array<string, mixed>>  $positionRows
     * @return array<string, mixed>
     */
    private function editorData(array $setRows, PermissionSetsQuery $sets, array $catalogRows, array $positionRows): array
    {
        $set = $this->editingSetId === null ? null : collect($setRows)->firstWhere('id', $this->editingSetId);
        $holders = array_column(array_map(fn (array $row): array => ['value' => $row['role']->value, 'holders' => $row['holders']], $positionRows), 'holders', 'value');
        $labels = array_column($catalogRows, 'label', 'key');
        $original = $set === null ? [] : array_column(array_merge(...array_values($set['permissions'])), 'key');
        $chosen = array_values(array_unique($this->permissions));
        sort($chosen);
        sort($original);
        $factory = $set['factory_role'] ?? null;

        return [
            'isNew' => $set === null,
            'id' => $set['id'] ?? null,
            'title' => $set === null ? 'Nuevo rol' : 'Editar rol',
            'subtitle' => $set['name'] ?? 'Un paquete de permisos que das a uno o más puestos.',
            'factoryLabel' => $factory?->listLabel(),
            'follows' => $factory !== null && $set['follows_factory'],
            'edited' => $factory !== null && ! $set['follows_factory'],
            'newFactory' => array_map(fn (string $key): string => $labels[$key] ?? $key, $set['new_factory'] ?? []),
            'holders' => array_map(fn (Role $role): array => ['label' => $role->listLabel(), 'people' => $holders[$role->value] ?? 0], $set['positions'] ?? []),
            'groups' => $this->pickerGroups($this->editorRows($setRows, $sets, $catalogRows), [], fn (array $group): string => count(array_filter($group, fn (array $row): bool => $row['checked'])).'/'.count($group)),
            'marked' => count($chosen),
            'leavesFactory' => $factory !== null && $set['follows_factory'] && $chosen !== $original,
        ];
    }

    /**
     * Los renglones del editor: lo que el rol no trae y valdría en todo el Tec para algún puesto que lo tiene lleva el
     * aviso (15 de noviembre de 2026).
     *
     * @param  list<array<string, mixed>>  $setRows
     * @param  list<array<string, mixed>>  $catalogRows
     * @return list<array<string, mixed>>
     */
    private function editorRows(array $setRows, PermissionSetsQuery $sets, array $catalogRows): array
    {
        $problems = $sets->addProblems($this->editingSetId, $setRows);
        $set = $this->editingSetId === null ? null : collect($setRows)->firstWhere('id', $this->editingSetId);
        $original = $set === null ? [] : array_column(array_merge(...array_values($set['permissions'])), 'key');
        $holders = $set['positions'] ?? [];

        return array_map(function (array $permission) use ($problems, $original, $holders): array {
            $checked = in_array($permission['key'], $this->permissions, true);
            $problem = $problems[$permission['key']] ?? null;
            $wide = in_array($permission['key'], $original, true) ? [] : array_values(array_filter($holders, fn (Role $role): bool => in_array($role, $permission['institution_wide_for'], true)));

            return [
                'key' => $permission['key'],
                'label' => $permission['label'],
                'group' => $permission['group'],
                'group_label' => $permission['group_label'],
                'checked' => $checked,
                'disabled' => $problem !== null && ! $checked,
                'problem' => $checked ? null : $problem,
                'locked' => $permission['admin_only'],
                'note' => null,
                'wide' => $wide === [] || $problem !== null ? null : 'Valdrá en todo el Tec para '.implode(', ', array_map(fn (Role $role): string => $role->listLabel(), $wide)).'.',
            ];
        }, $catalogRows);
    }

    /**
     * Los grupos de la lista de permisos de un drawer, con solo los renglones que dejan la búsqueda y «Marcados»; sin
     * los grupos que quedan vacíos. El grupo dice su cifra (`$count`) y si sus renglones habilitados (todos, se vean o
     * no) están todos marcados o algunos. Abre todos si se busca o se ven los marcados; si no, los de `$open`.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $open
     * @param  Closure(list<array<string, mixed>>): string  $count
     * @return list<array<string, mixed>>
     */
    private function pickerGroups(array $rows, array $open, Closure $count): array
    {
        $filtering = $this->folded($this->pickerSearch) !== '' || $this->pickerMarked;
        $groups = [];

        foreach (PermissionCatalogQuery::grouped($rows) as $group) {
            $visible = array_values(array_filter($group['rows'], fn (array $row): bool => $this->isVisible($row)));

            if ($visible === []) {
                continue;
            }

            $enabled = array_filter($group['rows'], fn (array $row): bool => ! $row['disabled']);
            $on = count(array_filter($enabled, fn (array $row): bool => $row['checked']));

            $groups[] = [
                'id' => $group['id'],
                'label' => $group['label'],
                'rows' => $visible,
                'count' => $count($group['rows']),
                'all' => $enabled !== [] && $on === count($enabled),
                'some' => $on > 0 && $on < count($enabled),
                'toggleable' => array_any($visible, fn (array $row): bool => ! $row['disabled']),
                'open' => $filtering || in_array($group['id'], $open, true),
            ];
        }

        return $groups;
    }

    /**
     * Si el renglón de un drawer pasa la búsqueda (etiqueta o clave, sin acentos) y «Marcados».
     *
     * @param  array<string, mixed>  $row
     */
    private function isVisible(array $row): bool
    {
        $needle = $this->folded($this->pickerSearch);

        return ($needle === '' || str_contains($this->folded($row['label'].' '.$row['key']), $needle))
            && (! $this->pickerMarked || $row['checked']);
    }

    private function folded(string $text): string
    {
        return Str::lower(Str::ascii(trim($text)));
    }

    private function plural(int $count, string $one, string $many): string
    {
        return number_format($count).' '.($count === 1 ? $one : $many);
    }
};
