<?php

namespace App\Modules\Core\Queries;

use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\RoleScope;
use App\Modules\Core\Models\Term;
use App\Modules\Core\Models\User;
use Closure;
use Illuminate\Contracts\Session\Session;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

/**
 * Rol, alcance y contexto activo de un usuario (ADR-0004). Toda policy (con el trait
 * UsesScopedContext) y toda pantalla filtrada por alcance le preguntan a esta clase.
 *
 * - Un usuario inactivo, o ninguno, no tiene ningún rol.
 * - `admin` (rol de spatie) tiene todos los roles y lo cubre todo, sin alcance.
 * - Cualquier otro rol cuenta solo si el usuario tiene el rol de spatie **y** un alcance vigente
 *   de ese rol (RoleScope::current), en un campus activo y no borrado, que llene las columnas que
 *   el rol exige (Role::scopeColumns).
 * - Un alcance cubre un objetivo de su campus si no fija departamento o carrera, o si fija los
 *   del objetivo: el jefe de D cubre lo de D en cualquier carrera; el coordinador de X, lo de X en
 *   cualquier departamento.
 * - El docente nunca cubre por covers(): su acceso a su propia carga lo deciden las policies de
 *   Staffing y Scheduling por asignación. has(Role::Docente) y canAccessCampus() sí lo cuentan.
 *
 * El contenedor la registra con `scoped` (una instancia por petición, por Octane) para el usuario
 * autenticado; forUser() da, y recuerda en esta instancia, la de otro usuario. Lo cargado se
 * recuerda por petición: se vuelve a cargar si el usuario autenticado cambia o tras refresh().
 */
final class ScopedContext
{
    /**
     * Claves de sesión del campus y periodo elegidos en la topbar; las escribe SetActiveContext.
     */
    public const string ACTIVE_CAMPUS = 'core.active_campus_id';

    public const string ACTIVE_TERM = 'core.active_term_id';

    private bool $loaded = false;

    private int|string|null $loadedFor = null;

    private bool $admin = false;

    /** @var Collection<int, RoleScope> */
    private Collection $scopes;

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

        return $this->others[$user->getKey()] ??= new self(fn (): User => $user);
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
     * Vuelve a cargar roles y alcances, también los de forUser(), en la siguiente pregunta. La
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
     * Si el usuario, con alguno de `$roles`, cubre el objetivo: un campus completo, o un
     * departamento o una carrera dentro de él. El admin, siempre; el docente, nunca.
     *
     * @param  Role|list<Role>  $roles
     */
    public function covers(Role|array $roles, Campus|string $campus, ?string $department = null, ?string $career = null): bool
    {
        if ($this->isAdmin()) {
            return true;
        }

        $roles = array_filter(is_array($roles) ? $roles : [$roles], fn (Role $role): bool => $role !== Role::Docente);
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return $this->scopes()->contains(fn (RoleScope $scope): bool => in_array($scope->role, $roles, true)
            && $scope->campus_id === $campusId
            && ($scope->department_id === null || $scope->department_id === $department)
            && ($scope->career_id === null || $scope->career_id === $career));
    }

    /**
     * Si el usuario trabaja en el campus con algún rol, aunque sea sobre una parte de él.
     */
    public function canAccessCampus(Campus|string $campus): bool
    {
        $campusId = $campus instanceof Campus ? $campus->getKey() : $campus;

        return $this->isAdmin() || $this->scopes()->contains(fn (RoleScope $scope): bool => $scope->campus_id === $campusId);
    }

    /**
     * Alcances vigentes, completos y en campus activos, de todos sus roles o solo de `$role`.
     *
     * @return Collection<int, RoleScope>
     */
    public function scopes(?Role $role = null): Collection
    {
        $this->user();

        return $role === null
            ? $this->scopes
            : $this->scopes->filter(fn (RoleScope $scope): bool => $scope->role === $role)->values();
    }

    /**
     * Campus activos donde trabaja, por código: lo que ofrece el selector de la topbar.
     *
     * @return Collection<int, Campus>
     */
    public function campuses(): Collection
    {
        if ($this->user() === null) {
            return new Collection;
        }

        return $this->campuses ??= Campus::query()
            ->where('is_active', true)
            ->when(! $this->admin, fn (Builder $query) => $query->whereKey($this->scopes->pluck('campus_id')->unique()->all()))
            ->orderBy('code')
            ->get();
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

    private function resolvedUser(): ?User
    {
        $user = ($this->resolveUser)();

        return $user instanceof User && $user->is_active ? $user : null;
    }

    private function load(?User $user): void
    {
        $this->loaded = true;
        $this->loadedFor = $user?->getKey();
        $this->campuses = null;
        $this->terms = [];
        $this->admin = $user?->hasRole(Role::Admin) ?? false;
        $this->scopes = $user === null ? new Collection : $user->roleScopes()
            ->current()
            ->whereIn('role', $user->getRoleNames()->all())
            ->whereHas('campus', fn (Builder $query) => $query->where('is_active', true))
            ->get()
            ->filter(fn (RoleScope $scope): bool => collect($scope->role->scopeColumns())
                ->every(fn (string $column): bool => $scope->{$column} !== null))
            ->values();
    }
}
