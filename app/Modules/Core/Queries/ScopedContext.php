<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Authorization\OrgTree;
use App\Modules\Core\Enums\OrgUnitKind;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rol, alcance y contexto activo de un usuario (ADR-0004, ADR-0018). Toda policy (con el trait
 * UsesScopedContext) y toda pantalla filtrada por alcance le preguntan a esta clase.
 *
 * - Un usuario inactivo, o ninguno, no tiene ningún rol.
 * - `admin` (rol de spatie) tiene todos los roles y lo cubre todo, sin alcance. Su nombramiento `admin` no es un
 *   alcance: no sale en scopes().
 * - Cualquier otro rol cuenta solo si el usuario tiene el rol de spatie **y** un nombramiento vigente de ese rol
 *   (RoleAssignment::current), sin campus o en un campus activo y no borrado, que llene las columnas que el rol
 *   exige (Role::scopeColumns).
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
 *   nombran puestos sin unidad o a la Subdirección. Esta regla vive solo en esta API de compatibilidad y se va en
 *   3.P9, con allows()/constrain().
 * - El docente nunca cubre por covers() ni por coversDepartment(): su acceso a su propia carga lo
 *   deciden las policies de Staffing y Scheduling por asignación. has(Role::Docente) y
 *   canAccessCampus() sí lo cuentan. Los campus de la credencial (campuses()) siguen a sus otros
 *   roles si los tiene: el nombramiento de docente solo los decide para quien no ejerce otro rol.
 * - Por ahora valen todos sus puestos a la vez; el perfil activo (ADR-0018) los acotará.
 *
 * El contenedor la registra con `scoped` (una instancia por petición, por Octane) para el usuario
 * autenticado; forUser() da, y recuerda en esta instancia, la de otro usuario. Lo cargado se
 * recuerda por petición: se vuelve a cargar si el usuario autenticado cambia o tras refresh(). El árbol de
 * unidades (OrgTree, también `scoped`) se lee una vez por petición: un movimiento de unidades cuenta desde la
 * siguiente.
 */
final class ScopedContext
{
    /**
     * Claves de sesión del campus y periodo elegidos en la credencial; las escribe SetActiveContext.
     */
    public const string ACTIVE_CAMPUS = 'core.active_campus_id';

    public const string ACTIVE_TERM = 'core.active_term_id';

    private bool $loaded = false;

    private int|string|null $loadedFor = null;

    private bool $admin = false;

    /** @var Collection<int, RoleAssignment> */
    private Collection $scopes;

    /**
     * Si tiene algún nombramiento vigente y completo de un rol distinto de docente, aunque sea en un
     * campus desactivado o borrado: entonces la credencial sigue a esos roles (campuses()).
     */
    private bool $holdsNonTeacherScope = false;

    /** @var Collection<int, Campus>|null todos los campus activos, por código */
    private ?Collection $activeCampuses = null;

    /** @var Collection<int, Campus>|null */
    private ?Collection $campuses = null;

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
        private readonly ?Session $session = null,
    ) {
        $this->scopes = new Collection;
    }

    /**
     * Este mismo contexto si `$user` es el autenticado; si no, uno nuevo de `$user`, sin el
     * campus ni el periodo que eligió en su sesión.
     */
    public function forUser(User $user): self
    {
        if ($this->resolvedUser()?->is($user)) {
            return $this;
        }

        return $this->others[$user->getKey()] ??= new self(fn (): User => $user, $this->orgTree);
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
     * Vuelve a cargar roles y nombramientos, también los de forUser(), en la siguiente pregunta. La
     * llama la Action que cambie roles o alcances a media petición.
     */
    public function refresh(): void
    {
        $this->resolvedUser()?->unsetRelation('roles');
        $this->loaded = false;

        foreach ($this->others as $context) {
            $context->refresh();
        }
    }

    public function isAdmin(): bool
    {
        $this->user();

        return $this->admin;
    }

    /**
     * Si el usuario ejerce `$role` en algún lugar. El admin, todos.
     */
    public function has(Role $role): bool
    {
        return $this->isAdmin() || $this->scopes($role)->isNotEmpty();
    }

    /**
     * Los roles que el usuario ejerce, en el orden del enum; el admin, que los tiene todos, solo
     * Admin. Ninguno sin usuario activo ni alcance vigente.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        if ($this->isAdmin()) {
            return [Role::Admin];
        }

        return array_values(array_filter(Role::cases(), fn (Role $role): bool => $this->has($role)));
    }

    /**
     * Si el usuario, con alguno de `$roles`, cubre el objetivo: un campus completo, o un
     * departamento o una carrera dentro de él. Un alcance sin campus cubre en cualquier campus
     * activo. El admin, siempre; el docente, nunca.
     *
     * @param  Role|list<Role>  $roles
     */
    public function covers(Role|array $roles, Campus|string $campus, ?string $department = null, ?string $career = null): bool
    {
        return $this->isAdmin() || $this->coversWithScope($roles, $campus, $department, $career);
    }

    /**
     * Lo mismo que covers(), solo por sus alcances: sin el atajo del admin, que lo cubre todo sin tener
     * alcance. Para saber a quién le toca un aviso (RecipientsQuery): un admin que además es jefe recibe los
     * de su departamento, y no los de todos.
     *
     * @param  Role|list<Role>  $roles
     */
    public function coversWithScope(Role|array $roles, Campus|string $campus, ?string $department = null, ?string $career = null): bool
    {
        $roles = $this->withoutDocente($roles);
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return $this->scopes()->contains(fn (RoleAssignment $scope): bool => in_array($scope->role, $roles, true)
            && $this->coversUnit($scope, $department)
            && ($scope->career_id === null || $scope->career_id === $career)
            && $this->appliesIn($scope, $campusId));
    }

    /**
     * Si el usuario, con alguno de `$roles`, cubre el departamento completo, en cualquier campus:
     * un nombramiento de esos roles sin unidad o sobre el departamento o una unidad de la que cuelga, y sin
     * carrera, con o sin campus (el coordinador, cuyo alcance es una carrera, nunca). Para lo que no es de un
     * campus, como los docentes. El admin, siempre; el docente, nunca.
     *
     * @param  Role|list<Role>  $roles
     */
    public function coversDepartment(Role|array $roles, string $department): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $roles = $this->withoutDocente($roles);

        return $this->scopes()->contains(fn (RoleAssignment $scope): bool => in_array($scope->role, $roles, true)
            && ($scope->org_unit_id === null || $this->orgTree->covers($scope->org_unit_id, $department))
            && $scope->career_id === null);
    }

    /**
     * Si el usuario trabaja en el campus con algún rol, aunque sea sobre una parte de él. Con un
     * alcance sin campus, también el del docente, trabaja en todos los activos.
     */
    public function canAccessCampus(Campus|string $campus): bool
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return $this->isAdmin() || $this->scopes()->contains(fn (RoleAssignment $scope): bool => $this->appliesIn($scope, $campusId));
    }

    /**
     * Nombramientos vigentes, completos y sin campus o en campus activos, de todos sus roles salvo admin o solo
     * de `$role`. Cada uno trae `unit_is_department` (si su unidad es un departamento académico).
     *
     * @return Collection<int, RoleAssignment>
     */
    public function scopes(?Role $role = null): Collection
    {
        $this->user();

        return $role === null
            ? $this->scopes
            : $this->scopes->filter(fn (RoleAssignment $scope): bool => $scope->role === $role)->values();
    }

    /**
     * Campus activos donde trabaja, por código: lo que ofrece el selector de la credencial. Salen
     * de sus alcances de roles distintos de docente si tiene alguno vigente, aunque su campus ya
     * esté desactivado o borrado (entonces puede no quedar ninguno); solo quien no tiene otro rol
     * que docente los toma de sus alcances de docente. Con un alcance sin campus de los que
     * cuentan, todos los activos. Así, el alcance de docente de todo el Tec de un coordinador de C2
     * no le ofrece otros campus, aunque canAccessCampus() diga que da clase en cualquiera.
     *
     * @return Collection<int, Campus>
     */
    public function campuses(): Collection
    {
        if ($this->user() === null) {
            return new Collection;
        }

        if ($this->campuses !== null) {
            return $this->campuses;
        }

        if ($this->admin) {
            return $this->campuses = $this->activeCampuses();
        }

        $scopes = $this->holdsNonTeacherScope
            ? $this->scopes->reject(fn (RoleAssignment $scope): bool => $scope->role === Role::Docente)
            : $this->scopes;

        if ($scopes->contains(fn (RoleAssignment $scope): bool => $scope->campus_id === null)) {
            return $this->campuses = $this->activeCampuses();
        }

        $campusIds = $scopes->pluck('campus_id')->all();

        return $this->campuses = $this->activeCampuses()
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
     * Si el nombramiento cubre la unidad del objetivo: sin unidad, siempre; con departamento, si es su unidad o
     * una de sus descendientes (OrgTree); sin departamento, solo si su unidad no es un departamento académico (ver
     * el docblock de la clase).
     */
    private function coversUnit(RoleAssignment $scope, ?string $department): bool
    {
        if ($scope->org_unit_id === null) {
            return true;
        }

        return $department === null
            ? ! $scope->unit_is_department
            : $this->orgTree->covers($scope->org_unit_id, $department);
    }

    /**
     * Si el nombramiento vale en el campus: el suyo o, sin campus, cualquiera activo y no borrado.
     */
    private function appliesIn(RoleAssignment $scope, string $campusId): bool
    {
        return $scope->campus_id === null
            ? $this->activeCampuses()->contains(fn (Campus $campus): bool => $campus->getKey() === $campusId)
            : $scope->campus_id === $campusId;
    }

    /**
     * Todos los campus activos y no borrados, por código; se consultan una vez por petición.
     *
     * @return Collection<int, Campus>
     */
    private function activeCampuses(): Collection
    {
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
        $this->loaded = true;
        $this->loadedFor = $user?->getKey();
        $this->activeCampuses = null;
        $this->campuses = null;
        $this->terms = [];
        $this->admin = $user?->hasRole(Role::Admin) ?? false;

        $complete = $user === null ? new Collection : $user->roleAssignments()
            ->current()
            ->where('role', '<>', Role::Admin)
            ->whereIn('role', $user->getRoleNames()->all())
            ->withExists([
                'campus as campus_is_active' => fn (Builder $query) => $query->where('is_active', true),
                'orgUnit as unit_is_department' => fn (Builder $query) => $query->withTrashed()->where('kind', OrgUnitKind::DepartamentoAcademico),
            ])
            ->get()
            ->filter(fn (RoleAssignment $scope): bool => collect($scope->role->scopeColumns())
                ->every(fn (string $column): bool => $scope->{$column} !== null));

        $this->holdsNonTeacherScope = $complete->contains(fn (RoleAssignment $scope): bool => $scope->role !== Role::Docente);
        $this->scopes = $complete
            ->filter(fn (RoleAssignment $scope): bool => $scope->campus_id === null || $scope->campus_is_active)
            ->values();
    }
}
