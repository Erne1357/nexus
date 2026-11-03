<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\AssignmentScope;
use App\Modules\Core\Authorization\AuthorizationSnapshot;
use App\Modules\Core\Authorization\AuthorizationSnapshots;
use App\Modules\Core\Authorization\Dimension;
use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Authorization\Permission;
use App\Modules\Core\Authorization\PermissionRegistry;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use LogicException;

/**
 * La persona en esta petición (ADR-0004, ADR-0018; el «Actor» de la spec 7): sus nombramientos, el campus y el
 * periodo activos y lo que puede hacer. Toda policy (con el trait UsesScopedContext) y toda pantalla filtrada por
 * alcance le preguntan a esta clase.
 *
 * Los nombramientos salen del snapshot cacheado (AuthorizationSnapshots): los vigentes hoy que llenan lo que su
 * puesto pide (Role::scopeColumns). Aquí se descartan los de un campus desactivado o borrado, para todo: no cuentan
 * ni en las dimensiones que un permiso no declara. Un usuario inactivo, o ninguno, no tiene ningún puesto. `admin`
 * (un nombramiento `admin` vigente) no es un alcance: no sale en scopes().
 *
 * Perfil activo (spec 8.1, AZ-S4): en la sesión de quien entró solo cuenta un puesto, el que eligió, con sus
 * nombramientos. activeRole() es la elección de la sesión (ACTIVE_ROLE) si todavía tiene ese puesto; si no, el último
 * que usó (`users.last_role`) si lo conserva; si no, el primero de heldRoles() en el orden del enum. Sin puestos no
 * hay perfil. Esta clase solo lo lee: lo escribe el cambio de perfil. Sus puestos efectivos (effectiveRoles()) son
 * solo ese; todo lo demás (allows(), allowsAny() y constrain() de gestión, has, isAdmin, scopes, covers,
 * coversDepartment, canAccessCampus y campuses) responde con ellos. El contexto de otro usuario (forUser()) y uno sin
 * sesión no tienen perfil activo: sus puestos efectivos son todos los que tiene, porque responden «quién podría», que
 * es lo que preguntan los avisos y las policies sobre otra persona.
 *
 * API de permisos (spec 7.1; el registro de permisos dice qué puesto concede qué):
 * - allows(): un permiso personal lo da cualquier puesto que tenga, aunque no sea el activo, solo sobre lo suyo (el
 *   dueño del objetivo es el usuario). Uno de gestión, alguno de sus puestos efectivos que lo conceda: el admin, sobre
 *   todo; otro, si alguno de sus nombramientos cubre el objetivo en cada dimensión que el permiso declara. Un
 *   nombramiento que no fija una dimensión la cubre (en campus, solo los activos; un objetivo sin campus también); uno
 *   que la fija cubre si algún valor del objetivo coincide (en unidad, la unidad o una descendiente, OrgTree) y no
 *   cubre un objetivo sin valor. Las dimensiones que el permiso no declara no se revisan.
 * - allowsAny(): si puede sobre algo: algún puesto efectivo (cualquiera que tenga, si es personal) lo concede. Todo
 *   puesto que tiene cuenta con al menos un nombramiento que vale, o es el admin.
 * - constrain(): la misma regla en SQL, para las listas.
 *
 * API de compatibilidad (has, covers, coversWithScope, coversDepartment, canAccessCampus, scopes), que se va en 3.P9.
 * Responde con los puestos efectivos, salvo coversWithScope(), que cuenta todos (ver abajo):
 * - El admin efectivo tiene todos los roles y lo cubre todo, sin alcance.
 * - Cualquier otro rol cuenta solo si es efectivo y el usuario tiene un nombramiento de ese rol que vale (ver arriba).
 * - Un nombramiento sin campus es de todo el Tec: vale en cada campus activo y no borrado (los desactivados o
 *   borrados quedan para el admin). Uno con campus vale solo en ese campus, aunque su rol no pida campus.
 * - Un nombramiento cubre un objetivo de un campus donde vale si cubre su unidad y su carrera. La carrera, como
 *   siempre: sin carrera, o la del objetivo. La unidad es jerárquica (OrgTree): sin unidad cubre cualquiera; con una,
 *   cubre la del objetivo si es ella o una de sus descendientes. Así el jefe de D cubre lo de D en cualquier carrera,
 *   el coordinador de X lo de X en cualquier departamento, y la Subdirección, sobre Subdirección Académica, los
 *   departamentos de su subárbol (deja de cubrir uno que se mueve fuera). coversDepartment() pregunta por un
 *   departamento sin campus.
 * - Un objetivo sin departamento (un campus completo, o una carrera sin decir su departamento) lo cubre el
 *   nombramiento sin unidad o con una unidad que no es un departamento académico, como la de la Subdirección. Es la
 *   traducción fiel de la regla de antes, cuando «sin departamento» era «todos los departamentos» y solo la
 *   Subdirección lo tenía: hoy su equivalente es una unidad estructural por encima de los departamentos. El jefe y
 *   el jefe de proyecto docente, sobre un departamento, no cubren el campus completo. Las llamadas actuales sin
 *   departamento (CampusPolicy, BuildingPolicy, RoomPolicy, CareerProposalPolicy y los avisos a coordinadores) solo
 *   nombran puestos sin unidad o a la Subdirección. Esta regla vive solo en esta API de compatibilidad.
 * - El docente nunca cubre por covers() ni por coversDepartment(): su acceso a su propia carga lo
 *   deciden las policies de Staffing y Scheduling por asignación. has(Role::Docente) y
 *   canAccessCampus() sí lo cuentan.
 * - Los campus de la credencial (campuses()) salen de los nombramientos efectivos, como todo lo demás: con
 *   Coordinación activa, los de sus coordinaciones; con Docente activa, los de su nombramiento de docente.
 * - coversWithScope() cuenta todos sus puestos, aunque no sean el activo: dice a quién le toca un aviso
 *   (RecipientsQuery), y forUser() del usuario autenticado es este mismo contexto. Un jefe que entró como
 *   coordinador recibe los avisos de su departamento (spec 8.4).
 *
 * El contenedor la registra con `scoped` (una instancia por petición, por Octane) para el usuario
 * autenticado; forUser() da, y recuerda en esta instancia, la de otro usuario, que comparte con esta los campus
 * activos. Lo cargado se recuerda por petición: se vuelve a cargar si el usuario autenticado cambia o tras refresh().
 * El árbol de unidades (OrgTree, también `scoped`) se lee una vez por petición: un movimiento de unidades cuenta
 * desde la siguiente.
 */
final class ScopedContext
{
    /**
     * Claves de sesión del campus y periodo elegidos en la credencial; las escribe SetActiveContext.
     */
    public const string ACTIVE_CAMPUS = 'core.active_campus_id';

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

    /** @var list<AssignmentScope> nombramientos que cuentan: sin el de admin, sin campus o en un campus activo */
    private array $assignments = [];

    /** @var list<Role> puestos que tiene, el admin incluido, en el orden del enum */
    private array $heldRoles = [];

    /** @var Collection<int, RoleAssignment>|null los nombramientos que cuentan, como modelos, para scopes() */
    private ?Collection $scopeModels = null;

    /** @var Collection<int, Campus>|null todos los campus activos, por código */
    private ?Collection $activeCampuses = null;

    /** El contexto que creó este con forUser(): de él salen los campus activos. */
    private ?self $campusSource = null;

    /** @var array<string, Collection<int, Campus>> campuses() por perfil activo ('' sin perfil) */
    private array $campuses = [];

    /** @var array<string, Term|null> periodo activo por elección de sesión ('' sin elección) */
    private array $terms = [];

    /** @var array<int|string, self> contextos de otros usuarios, por id */
    private array $others = [];

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
     * campus, el periodo ni el perfil que eligió en su sesión: cuenta todos sus puestos.
     */
    public function forUser(User $user): self
    {
        if ($this->resolvedUser()?->is($user)) {
            return $this;
        }

        if (! isset($this->others[$user->getKey()])) {
            $other = new self(fn (): User => $user, $this->orgTree, $this->snapshots, $this->permissions);
            $other->campusSource = $this;
            $this->others[$user->getKey()] = $other;
        }

        return $this->others[$user->getKey()];
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

        return array_any($this->assignmentsOf($roles), fn (AssignmentScope $scope): bool => $this->coversTarget($scope, $permission, $target));
    }

    /**
     * Si puede `$permission` sobre algo: para el menú y la entrada a una pantalla de lista, que luego se filtra con
     * constrain(). Uno personal lo da cualquier puesto que tenga; uno de gestión, un puesto efectivo.
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

        $scopes = $this->assignmentsOf($roles);

        return $query->where(function (Builder $any) use ($scopes, $permission, $mappings): void {
            $any->whereRaw('false');

            foreach ($scopes as $scope) {
                $any->orWhere(function (Builder $clause) use ($scope, $permission, $mappings): void {
                    $clause->whereRaw('true');

                    foreach ($permission->dimensions() as $dimension) {
                        $this->constrainDimension($clause, $scope, $dimension, $mappings[$dimension->value]);
                    }
                });
            }
        });
    }

    /**
     * Si actúa como admin: con sesión, si el admin es su perfil activo; sin ella, si tiene el puesto.
     */
    public function isAdmin(): bool
    {
        return in_array(Role::Admin, $this->effectiveRoles(), true);
    }

    /**
     * Si el usuario ejerce `$role` en algún lugar con un puesto efectivo. El admin efectivo, todos.
     */
    public function has(Role $role): bool
    {
        return $this->isAdmin() || in_array($role, $this->effectiveRoles(), true);
    }

    /**
     * Alias de heldRoles(): todos sus puestos, el admin incluido, aunque no sean el activo. Lo usan la banda e
     * Inicio hasta que muestren el perfil activo.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        return $this->heldRoles();
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
     * El perfil con que actúa en su sesión (spec 8.1, AZ-S4): la elección de la sesión si todavía tiene ese puesto;
     * si no, `users.last_role` si lo conserva; si no, el primero de heldRoles(). Nulo sin puestos, y en el contexto
     * de otro usuario (forUser()) o sin sesión, que cuentan todos sus puestos. Solo lo lee: no escribe la sesión ni
     * `last_role`.
     */
    public function activeRole(): ?Role
    {
        $held = $this->heldRoles();

        if ($this->session === null || $held === []) {
            return null;
        }

        $selected = $this->session->get(self::ACTIVE_ROLE);
        $selected = $selected instanceof Role ? $selected : (is_string($selected) ? Role::tryFrom($selected) : null);

        foreach ([$selected, $this->user()?->last_role] as $candidate) {
            if ($candidate !== null && in_array($candidate, $held, true)) {
                return $candidate;
            }
        }

        return $held[0];
    }

    /**
     * Si el usuario, con alguno de `$roles` que sea efectivo, cubre el objetivo: un campus completo, o un
     * departamento o una carrera dentro de él. Un alcance sin campus cubre en cualquier campus
     * activo. El admin efectivo, siempre; el docente, nunca.
     *
     * @param  Role|list<Role>  $roles
     */
    public function covers(Role|array $roles, Campus|string $campus, ?string $department = null, ?string $career = null): bool
    {
        return $this->isAdmin() || $this->coversBy($this->effectiveAmong($roles), $campus, $department, $career);
    }

    /**
     * Lo mismo que covers(), solo por sus alcances y con todos sus puestos: sin el atajo del admin, que lo cubre
     * todo sin tener alcance, y sin acotar al perfil activo. Para saber a quién le toca un aviso (RecipientsQuery):
     * un admin que además es jefe recibe los de su departamento, y no los de todos; un jefe que entró como
     * coordinador también los recibe, porque forUser() de quien está en sesión es este mismo contexto.
     *
     * @param  Role|list<Role>  $roles
     */
    public function coversWithScope(Role|array $roles, Campus|string $campus, ?string $department = null, ?string $career = null): bool
    {
        return $this->coversBy($this->withoutDocente($roles), $campus, $department, $career);
    }

    /**
     * Si el usuario, con alguno de `$roles` que sea efectivo, cubre el departamento completo, en cualquier campus:
     * un nombramiento de esos roles sin unidad o sobre el departamento o una unidad de la que cuelga, y sin
     * carrera, con o sin campus (el coordinador, cuyo alcance es una carrera, nunca). Para lo que no es de un
     * campus, como los docentes. El admin efectivo, siempre; el docente, nunca.
     *
     * @param  Role|list<Role>  $roles
     */
    public function coversDepartment(Role|array $roles, string $department): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        return array_any($this->assignmentsOf($this->effectiveAmong($roles)), fn (AssignmentScope $scope): bool => ($scope->unitId === null || $this->orgTree->covers($scope->unitId, $department))
            && $scope->careerId === null);
    }

    /**
     * Si el usuario trabaja en el campus con un puesto efectivo, aunque sea sobre una parte de él. Con un
     * alcance sin campus, también el del docente, trabaja en todos los activos.
     */
    public function canAccessCampus(Campus|string $campus): bool
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return $this->isAdmin() || array_any($this->assignmentsOf($this->effectiveRoles()), fn (AssignmentScope $scope): bool => $this->appliesIn($scope, $campusId));
    }

    /**
     * Nombramientos vigentes, completos y sin campus o en campus activos, de sus puestos efectivos salvo admin o solo
     * de `$role`, como modelos sin guardar armados desde el snapshot (rol, campus, unidad y carrera). Cada uno trae
     * `unit_is_department` (si su unidad es un departamento académico).
     *
     * @return Collection<int, RoleAssignment>
     */
    public function scopes(?Role $role = null): Collection
    {
        $user = $this->user();
        $effective = $this->effectiveRoles();

        $this->scopeModels ??= (new Collection($this->assignments))->map(fn (AssignmentScope $scope): RoleAssignment => (new RoleAssignment)->forceFill([
            'user_id' => $user?->getKey(),
            'role' => $scope->role,
            'campus_id' => $scope->campusId,
            'org_unit_id' => $scope->unitId,
            'career_id' => $scope->careerId,
            'unit_is_department' => $scope->unitIsDepartment,
        ]));

        return $this->scopeModels
            ->filter(fn (RoleAssignment $scope): bool => in_array($scope->role, $effective, true) && ($role === null || $scope->role === $role))
            ->values();
    }

    /**
     * Campus activos donde trabaja con sus puestos efectivos, por código: lo que ofrece el selector de la credencial.
     * Salen de los nombramientos efectivos; con uno sin campus, todos los activos. Así, con Coordinación activa, un
     * coordinador de C2 que también es docente de todo el Tec ve solo C2, y con Docente activa, todos. El admin
     * efectivo, todos los activos. Se recuerdan por perfil activo.
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
     * El campus elegido si todavía está en campuses(); si no, el único de campuses(), o ninguno.
     */
    public function activeCampus(): ?Campus
    {
        $campuses = $this->campuses();
        $selected = $this->session?->get(self::ACTIVE_CAMPUS);

        return ($selected === null ? null : $campuses->firstWhere('id', $selected))
            ?? ($campuses->count() === 1 ? $campuses->first() : null);
    }

    /**
     * El periodo elegido o, por omisión, el actual (`is_current`). Se recuerda por elección.
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
     * Los puestos con los que actúa: el perfil activo, con sesión; si no, todos los que tiene. Todo permiso de
     * gestión y toda la API de compatibilidad, salvo coversWithScope(), pasan por aquí.
     *
     * @return list<Role>
     */
    private function effectiveRoles(): array
    {
        $active = $this->activeRole();

        return $active === null ? $this->heldRoles() : [$active];
    }

    /**
     * Los de `$roles` que son efectivos, sin el docente (que no cubre por covers() ni por coversDepartment()).
     *
     * @param  Role|list<Role>  $roles
     * @return list<Role>
     */
    private function effectiveAmong(Role|array $roles): array
    {
        $effective = $this->effectiveRoles();

        return array_values(array_filter($this->withoutDocente($roles), fn (Role $role): bool => in_array($role, $effective, true)));
    }

    /**
     * Si algún nombramiento de `$roles` cubre el objetivo: su unidad, su carrera y el campus donde vale.
     *
     * @param  list<Role>  $roles
     */
    private function coversBy(array $roles, Campus|string $campus, ?string $department, ?string $career): bool
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return array_any($this->assignmentsOf($roles), fn (AssignmentScope $scope): bool => $this->coversUnit($scope, $department)
            && ($scope->careerId === null || $scope->careerId === $career)
            && $this->appliesIn($scope, $campusId));
    }

    /**
     * Los de `$roles` a los que el registro concede `$permission`. Lanza LogicException si nadie lo declaró.
     *
     * @param  list<Role>  $roles
     * @return list<Role>
     */
    private function rolesGranting(Permission $permission, array $roles): array
    {
        return array_values(array_filter($roles, fn (Role $role): bool => $this->permissions->grants($role, $permission)));
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
        return array_all($permission->dimensions(), fn (Dimension $dimension): bool => $this->coversValues($scope, $dimension, $target->values($dimension)));
    }

    /**
     * @param  list<string>|null  $values  los del objetivo en la dimensión; null si no tiene
     */
    private function coversValues(AssignmentScope $scope, Dimension $dimension, ?array $values): bool
    {
        $fixed = $scope->value($dimension);

        if ($fixed === null) {
            return $dimension !== Dimension::Campus || $values === null || array_any($values, fn (string $campus): bool => $this->isActiveCampus($campus));
        }

        if ($values === null) {
            return false;
        }

        return match ($dimension) {
            Dimension::Campus => $this->isActiveCampus($fixed) && in_array($fixed, $values, true),
            Dimension::Unit => array_any($values, fn (string $unit): bool => $this->orgTree->covers($fixed, $unit)),
            Dimension::Career => in_array($fixed, $values, true),
        };
    }

    /**
     * Dónde está cada dimensión que el permiso declara; falla si falta una o si el campus no es una columna.
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

            $mappings[$dimension->value] = $mapping;
        }

        return $mappings;
    }

    /**
     * La parte de la cláusula de un nombramiento que toca a una dimensión (ver constrain()).
     *
     * @param  Builder<covariant Model>  $clause
     */
    private function constrainDimension(Builder $clause, AssignmentScope $scope, Dimension $dimension, string|Closure $mapping): void
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

    /**
     * Si el nombramiento cubre la unidad del objetivo: sin unidad, siempre; con departamento, si es su unidad o
     * una de sus descendientes (OrgTree); sin departamento, solo si su unidad no es un departamento académico (ver
     * el docblock de la clase).
     */
    private function coversUnit(AssignmentScope $scope, ?string $department): bool
    {
        if ($scope->unitId === null) {
            return true;
        }

        return $department === null
            ? ! $scope->unitIsDepartment
            : $this->orgTree->covers($scope->unitId, $department);
    }

    /**
     * Si el nombramiento vale en el campus: el suyo o, sin campus, cualquiera activo y no borrado.
     */
    private function appliesIn(AssignmentScope $scope, string $campusId): bool
    {
        return $scope->campusId === null
            ? $this->isActiveCampus($campusId)
            : $scope->campusId === $campusId;
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
        if ($this->campusSource !== null) {
            return $this->campusSource->activeCampuses();
        }

        return $this->activeCampuses ??= Campus::query()->where('is_active', true)->orderBy('code')->get();
    }

    /**
     * @param  Role|list<Role>  $roles
     * @return list<Role>
     */
    private function withoutDocente(Role|array $roles): array
    {
        return array_values(array_filter(is_array($roles) ? $roles : [$roles], fn (Role $role): bool => $role !== Role::Docente));
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
        $this->campuses = [];
        $this->scopeModels = null;
        $this->terms = [];

        $snapshot = $user === null ? new AuthorizationSnapshot : $this->snapshots->for($user);
        $this->admin = array_any($snapshot->scopes(Role::Admin), fn (AssignmentScope $scope): bool => $scope->campusId === null && $scope->unitId === null && $scope->careerId === null);
        $scoped = array_filter($snapshot->scopes(), fn (AssignmentScope $scope): bool => $scope->role !== Role::Admin);
        $this->assignments = array_values(array_filter($scoped, fn (AssignmentScope $scope): bool => $scope->campusId === null || $this->isActiveCampus($scope->campusId)));

        $held = array_map(fn (AssignmentScope $scope): Role => $scope->role, $this->assignments);
        $this->heldRoles = array_values(array_filter(Role::cases(), fn (Role $role): bool => $role === Role::Admin ? $this->admin : in_array($role, $held, true)));
    }
}
