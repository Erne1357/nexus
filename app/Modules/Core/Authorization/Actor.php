<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\KeyLookup;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;

/**
 * La persona en esta petición (ADR-0018, spec §7): sus nombramientos, el periodo activo de su credencial y lo que
 * puede hacer. Toda policy (con el trait AuthorizesActor) y toda pantalla filtrada por alcance le preguntan a esta
 * clase. La credencial ya no elige campus (#415): lo elige cada pantalla en su URL (WithCampusFilter).
 *
 * Los nombramientos salen del snapshot cacheado (AuthorizationSnapshots): los vigentes hoy que llenan lo que su
 * puesto pide (Role::scopeColumns). Aquí se descartan los de un campus o una modalidad desactivados o borrados (MS8),
 * para todo: no cuentan ni en las dimensiones que un permiso no declara. Se hace en cada petición, no en el snapshot,
 * para que activar o desactivar uno cuente sin esperar a que venza la caché. Un usuario inactivo, o ninguno, no tiene
 * ningún puesto. `admin` (un nombramiento `admin` vigente) no es un alcance: no sale en heldScopes().
 *
 * Perfil activo (spec 8.1, AZ-S4): en la sesión de quien entró solo cuenta un puesto, el que eligió, con sus
 * nombramientos. activeRole() es la elección de la sesión (ACTIVE_ROLE) si todavía tiene ese puesto; si no, el último
 * que usó (`users.last_role`) si lo conserva; si no, el primero de heldRoles() en el orden del enum, y ese de omisión
 * queda fijo en la sesión la primera vez que se pregunta. Sin puestos no hay perfil. Elegir otro es del cambio de
 * perfil (SwitchRole); asRole() responde como si ya hubiera cambiado, sin tocar la sesión. Sus puestos efectivos
 * (effectiveRoles()) son solo ese; todo lo demás (allows(), allowsAny() y constrain() de gestión, campuses() y
 * modalities()) responde con ellos. El contexto de otro usuario (forUser()) y uno sin usuario autenticado no tienen perfil activo (el código
 * de consola que fija el usuario del guard sí recibe el predeterminado): sus puestos efectivos son todos los que tiene,
 * porque responden «quién podría», que es lo que preguntan los avisos y las policies sobre otra persona.
 *
 * API de permisos (spec 7.1; el registro de permisos dice qué puesto concede qué):
 * - Qué nombramientos aportan un permiso (AZ-02; mecanismo AZ-S16, contributing()): los de un puesto al que el
 *   registro se lo concede y cuya unidad, o una de sus descendientes, es de las que el permiso declara en
 *   Permission::appliesTo() (OrgTree::fits()). Un nombramiento sin unidad, o un permiso sin aplicabilidad, lo aporta siempre. Así el jefe de
 *   Servicios Escolares no aporta lo académico aunque su puesto (jefe de departamento) lo tenga concedido. Todo lo de
 *   abajo cuenta solo esos nombramientos, y un puesto concede un permiso si el admin lo es o si alguno de sus
 *   nombramientos lo aporta (rolesGranting()): allows(), allowsAny(), constrain() y rolesAllowing() salen de esa
 *   misma definición.
 * - allows(): un permiso personal lo da cualquier puesto que tenga y lo conceda, aunque no sea el activo, solo sobre
 *   lo suyo (el dueño del objetivo es el usuario). Uno de gestión, alguno de sus puestos efectivos que lo conceda: el
 *   admin, sobre todo; otro, si alguno de los nombramientos que lo aportan cubre el objetivo en cada dimensión que el
 *   permiso declara. Un nombramiento que no fija una dimensión la cubre (en campus y en modalidad, solo los activos, y en modalidad también los inactivos si el permiso es ReachesInactiveModalities, M10; un
 *   objetivo sin campus o sin modalidad también); uno que la fija cubre si algún valor del objetivo coincide (en
 *   unidad, la unidad o una descendiente, OrgTree) y no cubre un objetivo sin valor. Las dimensiones que el permiso no
 *   declara no se revisan. Así se lee a nivel de modalidad y se escribe con campus (AZ-S17, MS6): un permiso de lectura
 *   declara la modalidad sin el campus y uno que escribe algo con campus declara los dos, y un coordinador o jefe de
 *   proyecto docente con campus pasa el primero en todos los campus de su modalidad y el segundo solo en el suyo.
 * - allowsAny(): si puede sobre algo: algún puesto efectivo (cualquiera que tenga, si es personal) lo concede.
 * - constrain(): la misma regla en SQL, para las listas, con una cláusula por nombramiento que lo aporta.
 * - rolesAllowing(): los puestos (todos los que tiene, sin el admin) con que allows() daría que sí sobre un objetivo; de ahí salen los
 *   destinatarios de un aviso (RecipientsQuery::holders()).
 *
 * El contenedor la registra con `scoped` (una instancia por petición, por Octane) para el usuario
 * autenticado; forUser() da, y recuerda en esta instancia, la de otro usuario, que comparte con esta los campus y las
 * modalidades activos. Lo cargado se recuerda por petición: se vuelve a cargar si el usuario autenticado cambia o tras
 * refresh().
 * El árbol de unidades (OrgTree, también `scoped`) se lee una vez por petición: un movimiento de unidades cuenta
 * desde la siguiente.
 */
final class Actor
{
    /**
     * Clave de sesión del periodo elegido en la credencial; la escribe SetActiveContext.
     */
    public const string ACTIVE_TERM = 'core.active_term_id';

    /**
     * Clave de sesión del perfil activo (el valor de un Role); la escribe el cambio de perfil.
     */
    public const string ACTIVE_ROLE = 'core.active_role';

    private bool $loaded = false;

    private int|string|null $loadedFor = null;

    /** Si refresh() pidió volver a leer `authz_version` del usuario en la siguiente carga. */
    private bool $staleVersion = false;

    private bool $admin = false;

    /** @var list<AssignmentScope> los que cuentan: sin el de admin, sin campus o en uno activo, sin modalidad o en una activa */
    private array $assignments = [];

    /** @var list<Role> puestos que tiene, el admin incluido, en el orden del enum */
    private array $heldRoles = [];

    /** @var Collection<int, RoleAssignment>|null los nombramientos que cuentan, como modelos, para heldScopes() */
    private ?Collection $scopeModels = null;

    /** @var Collection<int, Campus>|null todos los campus activos, por código */
    private ?Collection $activeCampuses = null;

    /** @var Collection<int, Modality>|null todas las modalidades activas, por código */
    private ?Collection $activeModalities = null;

    /** @var Collection<int, Modality>|null todas las modalidades no borradas, activas o no (M10), por código */
    private ?Collection $existingModalities = null;

    /** El contexto que creó este con forUser() o asRole(): de él salen los campus y las modalidades activos. */
    private ?self $catalogSource = null;

    /** @var array<string, Collection<int, Campus>> campuses() por perfil activo ('' sin perfil) */
    private array $campuses = [];

    /** @var array<string, Collection<int, Modality>> modalities() por perfil activo ('' sin perfil) */
    private array $modalities = [];

    /** @var array<string, Term|null> periodo activo por elección de sesión ('' sin elección) */
    private array $terms = [];

    /** @var array<int|string, self> contextos de otros usuarios, por id */
    private array $others = [];

    /** El perfil de un contexto de asRole(), que no lee la sesión; null en los demás. */
    private ?Role $pinnedRole = null;

    /**
     * @param  Closure(): mixed  $resolveUser  usuario de esta petición; sin sesión, el contexto
     *                                         activo es siempre el de omisión
     */
    public function __construct(
        private readonly Closure $resolveUser,
        private readonly OrgTree $orgTree,
        private readonly AuthorizationSnapshots $snapshots,
        private readonly PermissionRegistry $permissions,
        private readonly ?Session $session = null,
    ) {}

    /**
     * Este mismo contexto si `$user` es el autenticado; si no, uno nuevo de `$user`, sin el
     * periodo ni el perfil que eligió en su sesión: cuenta todos sus puestos.
     */
    public function forUser(User $user): self
    {
        if ($this->resolvedUser()?->is($user)) {
            return $this;
        }

        if (! isset($this->others[$user->getKey()])) {
            $other = new self(fn (): User => $user, $this->orgTree, $this->snapshots, $this->permissions);
            $other->catalogSource = $this;
            $this->others[$user->getKey()] = $other;
        }

        return $this->others[$user->getKey()];
    }

    /**
     * El contexto de esta misma persona actuando con `$role`, sin tocar la sesión: responde lo que respondería esta
     * sesión después de cambiar a ese perfil, para preguntar antes de cambiar (OpenNotification). Sus puestos
     * efectivos son solo `$role` si lo tiene, y ninguno si no; no tiene periodo ni perfil elegidos en sesión ni lee el
     * campus de la petición, así que campuses() y modalities() son los de ese perfil. Comparte con este los campus y
     * las modalidades activos.
     */
    public function asRole(Role $role): self
    {
        $user = $this->user();
        $context = new self(fn (): ?User => $user, $this->orgTree, $this->snapshots, $this->permissions);
        $context->catalogSource = $this;
        $context->pinnedRole = $role;

        return $context;
    }

    /**
     * El usuario del contexto, solo si está activo.
     */
    public function user(): ?User
    {
        $user = $this->resolvedUser();

        if (! $this->loaded || $user?->getKey() !== $this->loadedFor) {
            $this->load($user);
        }

        return $user;
    }

    /**
     * Vuelve a cargar los nombramientos, también los de forUser(), en la siguiente pregunta, con la `authz_version`
     * de la base (una consulta). La llama la Action que cambie nombramientos a media petición.
     */
    public function refresh(): void
    {
        $this->loaded = false;
        $this->staleVersion = true;

        foreach ($this->others as $context) {
            $context->refresh();
        }
    }

    /**
     * Si puede `$permission` sobre `$target` (spec 7.1, ver el docblock de la clase). Sin objetivo, un permiso de
     * gestión es allowsAny(); uno personal, falso, porque no hay dueño.
     */
    public function allows(Permission $permission, ?Target $target = null): bool
    {
        $user = $this->user();

        if ($user === null) {
            return false;
        }

        if ($permission->isPersonal()) {
            return $this->rolesGranting($permission, $this->heldRoles()) !== []
                && $target?->ownerId !== null
                && $target->ownerId === (string) $user->getKey();
        }

        if ($target === null) {
            return $this->allowsAny($permission);
        }

        $roles = $this->rolesGranting($permission, $this->effectiveRoles());

        if (in_array(Role::Admin, $roles, true)) {
            return true;
        }

        return array_any($this->contributing($permission, $roles), fn (AssignmentScope $scope): bool => $this->coversTarget($scope, $permission, $target));
    }

    /**
     * Los puestos que tiene (todos, no solo el activo; sin el admin) que conceden `$permission` y tienen algún
     * nombramiento que lo aporta y cubre `$target` en cada dimensión que el permiso declara: la regla de allows() sin el atajo
     * del admin, para saber a quién y con qué puesto le toca un aviso. En el orden del enum. Solo permisos de gestión.
     *
     * @return list<Role>
     */
    public function rolesAllowing(Permission $permission, Target $target): array
    {
        if ($permission->isPersonal()) {
            throw new LogicException("rolesAllowing() solo aplica a permisos de gestión; «{$permission->value}» es personal.");
        }

        $roles = array_values(array_filter(
            $this->rolesGranting($permission, $this->heldRoles()),
            fn (Role $role): bool => $role !== Role::Admin,
        ));

        return array_values(array_filter($roles, fn (Role $role): bool => array_any(
            $this->contributing($permission, [$role]),
            fn (AssignmentScope $scope): bool => $this->coversTarget($scope, $permission, $target),
        )));
    }

    /**
     * Si puede `$permission` sobre algo: para el menú y la entrada a una pantalla de lista, que luego se filtra con
     * constrain(). Uno personal lo da cualquier puesto que tenga; uno de gestión, un puesto efectivo; en los dos, el
     * puesto debe concederlo con un nombramiento que lo aporte (AZ-02, AZ-S16; rolesGranting()).
     */
    public function allowsAny(Permission $permission): bool
    {
        if ($this->user() === null) {
            return false;
        }

        $roles = $permission->isPersonal() ? $this->heldRoles() : $this->effectiveRoles();

        return $this->rolesGranting($permission, $roles) !== [];
    }

    /**
     * Filtra `$query` a los registros sobre los que puede `$permission`: la regla de allows() en SQL.
     *
     * `$columns` dice dónde está cada dimensión que el permiso declara (llave `Dimension->value`) y, si es personal,
     * el dueño (llave `owner`). Falta una y falla con LogicException: un permiso que se evalúa sin una de sus
     * dimensiones ampliaría el alcance.
     * - `campus` es siempre una columna: todo recurso de un campus tiene `campus_id`. Un nombramiento sin campus deja
     *   los campus activos y los registros sin campus (lo general), como allows().
     * - `modality` es siempre una columna, igual que el campus (AZ-S17): un nombramiento sin modalidad deja las
     *   modalidades activas y, solo si el permiso es ReachesInactiveModalities (M10: ver a los alumnos de una modalidad
     *   inactiva), también las inactivas no borradas, y los registros sin modalidad; uno con modalidad, solo esa y solo si sigue activa (MS8).
     * - `unit` y `career` son una columna o una Closure(Builder, list<string>): void, para cuando la dimensión sale de
     *   una relación. Un nombramiento que no las fija no agrega nada (también quedan los registros sin valor); uno que
     *   las fija pide los valores que cubre (en unidad, la unidad y sus descendientes) con whereIn, o se los pasa a la
     *   Closure. Los registros sin valor quedan fuera, como en allows().
     * - `owner` es una columna o una Closure que recibe [id del usuario].
     *
     * Sin un puesto que conceda el permiso no deja nada; con el admin entre ellos, deja la consulta igual. Si no, es
     * un OR con una cláusula por nombramiento, en un solo grupo.
     *
     * El filtro se agrega con AND a lo que la consulta ya tiene, así que un OR suelto lo saltaría (`a OR (b AND
     * filtro)`): con un `orWhere` en el primer nivel falla con LogicException, y las condiciones con OR van agrupadas
     * en un `where(function …)`. Por lo mismo, después de constrain() no se agrega ningún `orWhere` suelto.
     *
     * @template TModel of Model
     *
     * @param  Builder<TModel>  $query
     * @param  array<string, string|Closure(Builder<TModel>, list<string>): void>  $columns
     * @return Builder<TModel>
     */
    public function constrain(Builder $query, Permission $permission, array $columns): Builder
    {
        if (array_any($query->getQuery()->wheres, fn (array $where): bool => ($where['boolean'] ?? 'and') === 'or')) {
            throw new LogicException('constrain() no acepta un OR suelto en la consulta. Agrupa tus condiciones con OR en un where(function …) antes de llamar a constrain().');
        }

        if ($permission->isPersonal()) {
            $owner = $columns['owner']
                ?? throw new LogicException("constrain() necesita la columna del dueño («owner») para el permiso personal «{$permission->value}».");
            $user = $this->user();

            if ($user === null || $this->rolesGranting($permission, $this->heldRoles()) === []) {
                return $query->whereRaw('false');
            }

            return $this->whereValues($query, $owner, [(string) $user->getKey()]);
        }

        $mappings = $this->mappingsOf($permission, $columns);

        if ($this->user() === null) {
            return $query->whereRaw('false');
        }

        $roles = $this->rolesGranting($permission, $this->effectiveRoles());

        if ($roles === []) {
            return $query->whereRaw('false');
        }

        if (in_array(Role::Admin, $roles, true)) {
            return $query;
        }

        $scopes = $this->contributing($permission, $roles);

        return $query->where(function (Builder $any) use ($scopes, $permission, $mappings): void {
            $any->whereRaw('false');

            foreach ($scopes as $scope) {
                $any->orWhere(function (Builder $clause) use ($scope, $permission, $mappings): void {
                    $clause->whereRaw('true');

                    foreach ($permission->dimensions() as $dimension) {
                        $this->constrainDimension($clause, $scope, $permission, $dimension, $mappings[$dimension->value]);
                    }
                });
            }
        });
    }

    /**
     * Los puestos que tiene, el admin incluido, en el orden del enum: los que tienen un nombramiento que vale (ver el
     * docblock de la clase). Ninguno sin usuario activo ni nombramiento vigente. No depende del perfil activo.
     *
     * @return list<Role>
     */
    public function heldRoles(): array
    {
        $this->user();

        return $this->heldRoles;
    }

    /**
     * El perfil con que actúa en su sesión (spec 8.1, AZ-S4): la elección de la sesión (ACTIVE_ROLE) si todavía tiene
     * ese puesto. Si la sesión no tiene una que valga, resuelve el de omisión, `users.last_role` si lo conserva o el
     * primero de heldRoles(), y lo fija en la sesión: así un cambio de perfil en otra sesión (otro dispositivo), que
     * cambia `last_role`, no le cambia el perfil a esta. Nulo sin puestos, y en el contexto de otro usuario
     * (forUser()), que no tiene sesión y cuenta todos sus puestos. En uno de asRole(), ese puesto si lo tiene. No
     * escribe `last_role`: eso es del cambio de perfil.
     */
    public function activeRole(): ?Role
    {
        $held = $this->heldRoles();

        if ($this->pinnedRole !== null) {
            return in_array($this->pinnedRole, $held, true) ? $this->pinnedRole : null;
        }

        if ($this->session === null || $held === []) {
            return null;
        }

        $selected = $this->session->get(self::ACTIVE_ROLE);
        $selected = $selected instanceof Role ? $selected : (is_string($selected) ? Role::tryFrom($selected) : null);

        if ($selected !== null && in_array($selected, $held, true)) {
            return $selected;
        }

        $stored = $this->user()?->getAttributes()['last_role'] ?? null;
        $last = is_string($stored) ? Role::tryFrom($stored) : null;
        $default = $last !== null && in_array($last, $held, true) ? $last : $held[0];

        $this->session->put(self::ACTIVE_ROLE, $default->value);

        return $default;
    }

    /**
     * Los nombramientos de todos sus puestos (o solo de `$role`), sin importar el perfil activo: lo que la credencial
     * muestra por cada puesto. Son los vigentes, completos, sin campus o en uno activo y sin modalidad o en una activa,
     * como modelos sin guardar armados desde el snapshot (rol, campus, unidad, carrera y modalidad); el admin no sale,
     * porque no es un alcance.
     *
     * @return Collection<int, RoleAssignment>
     */
    public function heldScopes(?Role $role = null): Collection
    {
        return $this->allScopeModels()
            ->filter(fn (RoleAssignment $scope): bool => $role === null || $scope->role === $role)
            ->values();
    }

    /**
     * @return Collection<int, RoleAssignment>
     */
    private function allScopeModels(): Collection
    {
        $user = $this->user();

        return $this->scopeModels ??= (new Collection($this->assignments))->map(fn (AssignmentScope $scope): RoleAssignment => (new RoleAssignment)->forceFill([
            'user_id' => $user?->getKey(),
            'role' => $scope->role,
            'campus_id' => $scope->campusId,
            'org_unit_id' => $scope->unitId,
            'career_id' => $scope->careerId,
            'modality_id' => $scope->modalityId,
        ]));
    }

    /**
     * Campus activos donde trabaja con sus puestos efectivos, por código: lo que ofrece el campus de una pantalla
     * (WithCampusFilter). Salen de los nombramientos efectivos; con uno sin campus, todos los activos. Así, con
     * Coordinación activa, un coordinador de C2 que también es docente de todo el Tec ve solo C2, y con Docente activa,
     * todos. El admin efectivo, todos los activos. Se recuerdan por perfil activo.
     *
     * @return Collection<int, Campus>
     */
    public function campuses(): Collection
    {
        if ($this->user() === null) {
            return new Collection;
        }

        $key = $this->activeRole()?->value ?? '';

        if (isset($this->campuses[$key])) {
            return $this->campuses[$key];
        }

        $scopes = $this->assignmentsOf($this->effectiveRoles());

        if ($this->isAdmin() || array_any($scopes, fn (AssignmentScope $scope): bool => $scope->campusId === null)) {
            return $this->campuses[$key] = $this->activeCampuses();
        }

        $campusIds = array_map(fn (AssignmentScope $scope): ?string => $scope->campusId, $scopes);

        return $this->campuses[$key] = $this->activeCampuses()
            ->filter(fn (Campus $campus): bool => in_array($campus->getKey(), $campusIds, true))
            ->values();
    }

    /**
     * Modalidades activas donde trabaja con sus puestos efectivos, por código (AZ-S17): las de sus nombramientos
     * efectivos; con uno sin modalidad (jefe de departamento, Subdirección, docente), todas las activas. El admin
     * efectivo, todas las activas. Se recuerdan por perfil activo, como campuses().
     *
     * @return Collection<int, Modality>
     */
    public function modalities(): Collection
    {
        if ($this->user() === null) {
            return new Collection;
        }

        $key = $this->activeRole()?->value ?? '';

        if (isset($this->modalities[$key])) {
            return $this->modalities[$key];
        }

        $scopes = $this->assignmentsOf($this->effectiveRoles());

        if ($this->isAdmin() || array_any($scopes, fn (AssignmentScope $scope): bool => $scope->modalityId === null)) {
            return $this->modalities[$key] = $this->activeModalities();
        }

        $modalityIds = array_map(fn (AssignmentScope $scope): ?string => $scope->modalityId, $scopes);

        return $this->modalities[$key] = $this->activeModalities()
            ->filter(fn (Modality $modality): bool => in_array($modality->getKey(), $modalityIds, true))
            ->values();
    }

    /**
     * El periodo elegido en la credencial o, por omisión, el actual (`is_current`). Se recuerda por elección.
     */
    public function activeTerm(): ?Term
    {
        if ($this->user() === null) {
            return null;
        }

        $selected = $this->session?->get(self::ACTIVE_TERM);
        $key = (string) $selected;

        if (! array_key_exists($key, $this->terms)) {
            $this->terms[$key] = KeyLookup::find(Term::query(), $selected) ?? Term::current()->first();
        }

        return $this->terms[$key];
    }

    /**
     * Los puestos con los que actúa: el perfil activo, con sesión; si no, todos los que tiene. En un contexto de
     * asRole() sin ese puesto, ninguno. Todo permiso de gestión y campuses() pasan por aquí.
     *
     * @return list<Role>
     */
    private function effectiveRoles(): array
    {
        $active = $this->activeRole();

        if ($active !== null) {
            return [$active];
        }

        return $this->pinnedRole === null ? $this->heldRoles() : [];
    }

    /**
     * Si actúa como admin: con sesión, si el admin es su perfil activo; sin ella, si tiene el puesto.
     */
    private function isAdmin(): bool
    {
        return in_array(Role::Admin, $this->effectiveRoles(), true);
    }

    /**
     * Los de `$roles` que le conceden `$permission` a esta persona: el registro se lo concede al puesto y es el admin o
     * alguno de sus nombramientos lo aporta (contributing()). Lanza LogicException si nadie lo declaró.
     *
     * @param  list<Role>  $roles
     * @return list<Role>
     */
    private function rolesGranting(Permission $permission, array $roles): array
    {
        return array_values(array_filter($roles, fn (Role $role): bool => $this->permissions->grants($role, $permission)
            && ($role === Role::Admin || $this->contributing($permission, [$role]) !== [])));
    }

    /**
     * AZ-02 (mecanismo, AZ-S16): los nombramientos de `$roles` que aportan `$permission`: sin unidad, o con una unidad
     * a la que el permiso aplica (ella o una descendiente, OrgTree::fits()). Sin aplicabilidad declarada, todos. No
     * revisa que el registro se lo conceda al puesto: eso es de rolesGranting(), que pasa antes.
     *
     * @param  list<Role>  $roles
     * @return list<AssignmentScope>
     */
    private function contributing(Permission $permission, array $roles): array
    {
        $applicability = $permission->appliesTo();
        $scopes = $this->assignmentsOf($roles);

        if ($applicability === null) {
            return $scopes;
        }

        return array_values(array_filter(
            $scopes,
            fn (AssignmentScope $scope): bool => $scope->unitId === null || $this->orgTree->fits($scope->unitId, $applicability),
        ));
    }

    /**
     * @return list<AssignmentScope>
     */
    private function assignments(): array
    {
        $this->user();

        return $this->assignments;
    }

    /**
     * @param  list<Role>  $roles
     * @return list<AssignmentScope>
     */
    private function assignmentsOf(array $roles): array
    {
        return array_values(array_filter($this->assignments(), fn (AssignmentScope $scope): bool => in_array($scope->role, $roles, true)));
    }

    /**
     * Si el nombramiento cubre el objetivo en cada dimensión que el permiso declara.
     */
    private function coversTarget(AssignmentScope $scope, Permission $permission, Target $target): bool
    {
        return array_all($permission->dimensions(), fn (Dimension $dimension): bool => $this->coversValues($scope, $permission, $dimension, $target->values($dimension)));
    }

    /**
     * Un nombramiento sin campus cubre los campus activos; uno sin modalidad cubre las modalidades activas y, solo si el
     * permiso es ReachesInactiveModalities (M10, la lectura de alumnos), también las inactivas no borradas; uno que
     * fija campus o modalidad solo cubre ese valor si sigue activo (MS8).
     *
     * @param  list<string>|null  $values  los del objetivo en la dimensión; null si no tiene
     */
    private function coversValues(AssignmentScope $scope, Permission $permission, Dimension $dimension, ?array $values): bool
    {
        $fixed = $scope->value($dimension);

        if ($fixed === null) {
            return match ($dimension) {
                Dimension::Campus => $values === null || array_any($values, fn (string $campus): bool => $this->isActiveCampus($campus)),
                Dimension::Modality => $values === null || array_any($values, fn (string $modality): bool => $this->modalitiesReachedBy($permission)->contains(fn (Modality $reached): bool => $reached->getKey() === $modality)),
                default => true,
            };
        }

        if ($values === null) {
            return false;
        }

        return match ($dimension) {
            Dimension::Campus => $this->isActiveCampus($fixed) && in_array($fixed, $values, true),
            Dimension::Modality => $this->isActiveModality($fixed) && in_array($fixed, $values, true),
            Dimension::Unit => array_any($values, fn (string $unit): bool => $this->orgTree->covers($fixed, $unit)),
            Dimension::Career => in_array($fixed, $values, true),
        };
    }

    /**
     * Dónde está cada dimensión que el permiso declara; falla si falta una o si el campus o la modalidad no son una
     * columna.
     *
     * @param  array<string, string|Closure>  $columns
     * @return array<string, string|Closure>
     */
    private function mappingsOf(Permission $permission, array $columns): array
    {
        $mappings = [];

        foreach ($permission->dimensions() as $dimension) {
            $mapping = $columns[$dimension->value]
                ?? throw new LogicException("constrain() necesita dónde está la dimensión «{$dimension->value}» del permiso «{$permission->value}».");

            if ($dimension === Dimension::Campus && ! is_string($mapping)) {
                throw new LogicException("En constrain() el campus es una columna, no una Closure (permiso «{$permission->value}»).");
            }

            if ($dimension === Dimension::Modality && ! is_string($mapping)) {
                throw new LogicException("En constrain() la modalidad es una columna, no una Closure (permiso «{$permission->value}»).");
            }

            $mappings[$dimension->value] = $mapping;
        }

        return $mappings;
    }

    /**
     * La parte de la cláusula de un nombramiento que toca a una dimensión (ver constrain()).
     *
     * @param  Builder<covariant Model>  $clause
     */
    private function constrainDimension(Builder $clause, AssignmentScope $scope, Permission $permission, Dimension $dimension, string|Closure $mapping): void
    {
        $fixed = $scope->value($dimension);

        if ($dimension === Dimension::Campus && is_string($mapping)) {
            if ($fixed === null) {
                $clause->where(fn (Builder $campus) => $campus->whereIn($mapping, $this->activeCampuses()->modelKeys())->orWhereNull($mapping));
            } else {
                $this->whereValues($clause, $mapping, $this->isActiveCampus($fixed) ? [$fixed] : []);
            }

            return;
        }

        if ($dimension === Dimension::Modality && is_string($mapping)) {
            if ($fixed === null) {
                $clause->where(fn (Builder $modality) => $modality->whereIn($mapping, $this->modalitiesReachedBy($permission)->modelKeys())->orWhereNull($mapping));
            } else {
                $this->whereValues($clause, $mapping, $this->isActiveModality($fixed) ? [$fixed] : []);
            }

            return;
        }

        if ($fixed !== null) {
            $this->whereValues($clause, $mapping, $dimension === Dimension::Unit ? $this->orgTree->descendantsOrSelf($fixed) : [$fixed]);
        }
    }

    /**
     * @template TBuilder of Builder
     *
     * @param  TBuilder  $query
     * @param  list<string>  $values
     * @return TBuilder
     */
    private function whereValues(Builder $query, string|Closure $mapping, array $values): Builder
    {
        if ($values === []) {
            return $query->whereRaw('false');
        }

        return is_string($mapping)
            ? $query->whereIn($mapping, $values)
            : $query->where(fn (Builder $nested) => $mapping($nested, $values));
    }

    private function isActiveCampus(string $campusId): bool
    {
        return $this->activeCampuses()->contains(fn (Campus $campus): bool => $campus->getKey() === $campusId);
    }

    /**
     * Todos los campus activos y no borrados, por código; se consultan una vez por petición, y los contextos de
     * forUser() usan los de quien los creó.
     *
     * @return Collection<int, Campus>
     */
    private function activeCampuses(): Collection
    {
        if ($this->catalogSource !== null) {
            return $this->catalogSource->activeCampuses();
        }

        return $this->activeCampuses ??= Campus::query()->where('is_active', true)->orderBy('code')->get();
    }

    private function isActiveModality(string $modalityId): bool
    {
        return $this->activeModalities()->contains(fn (Modality $modality): bool => $modality->getKey() === $modalityId);
    }

    /**
     * Todas las modalidades activas y no borradas (MS8), por código; como activeCampuses().
     *
     * @return Collection<int, Modality>
     */
    private function activeModalities(): Collection
    {
        if ($this->catalogSource !== null) {
            return $this->catalogSource->activeModalities();
        }

        return $this->activeModalities ??= Modality::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * Las modalidades que cubre un nombramiento sin modalidad para `$permission`: las activas y, si el permiso alcanza
     * a las inactivas (M10), todas las no borradas.
     *
     * @return Collection<int, Modality>
     */
    private function modalitiesReachedBy(Permission $permission): Collection
    {
        return $permission instanceof ReachesInactiveModalities && $permission->reachesInactiveModalities()
            ? $this->existingModalities()
            : $this->activeModalities();
    }

    /**
     * Todas las modalidades no borradas, activas o no (M10). Como activeModalities(), la comparten los contextos
     * derivados.
     *
     * @return Collection<int, Modality>
     */
    private function existingModalities(): Collection
    {
        if ($this->catalogSource !== null) {
            return $this->catalogSource->existingModalities();
        }

        return $this->existingModalities ??= Modality::query()->orderBy('code')->get();
    }

    private function resolvedUser(): ?User
    {
        $user = ($this->resolveUser)();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    private function load(?User $user): void
    {
        if ($this->staleVersion && $user !== null) {
            $user->forceFill(['authz_version' => User::query()->whereKey($user->getKey())->value('authz_version')])
                ->syncOriginalAttribute('authz_version');
        }

        $this->staleVersion = false;
        $this->loaded = true;
        $this->loadedFor = $user?->getKey();
        $this->activeCampuses = null;
        $this->activeModalities = null;
        $this->existingModalities = null;
        $this->campuses = [];
        $this->modalities = [];
        $this->scopeModels = null;
        $this->terms = [];

        $snapshot = $user === null ? new AuthorizationSnapshot : $this->snapshots->for($user);
        $this->admin = array_any($snapshot->scopes(Role::Admin), fn (AssignmentScope $scope): bool => $scope->campusId === null && $scope->unitId === null && $scope->careerId === null && $scope->modalityId === null);
        $scoped = array_filter($snapshot->scopes(), fn (AssignmentScope $scope): bool => $scope->role !== Role::Admin);
        $this->assignments = array_values(array_filter($scoped, fn (AssignmentScope $scope): bool => ($scope->campusId === null || $this->isActiveCampus($scope->campusId))
            && ($scope->modalityId === null || $this->isActiveModality($scope->modalityId))));

        $held = array_map(fn (AssignmentScope $scope): Role => $scope->role, $this->assignments);
        $this->heldRoles = array_values(array_filter(Role::cases(), fn (Role $role): bool => $role === Role::Admin ? $this->admin : in_array($role, $held, true)));
    }
}
