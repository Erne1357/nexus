<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;

/**
 * Lo que una persona tiene (spec 7 y 9): sus nombramientos vigentes hoy que llenan lo que su puesto pide
 * (Role::scopeColumns), el de admin incluido, como datos planos que se guardan en caché. No sabe qué campus están
 * activos: ese filtro lo aplica ScopedContext en cada petición, así que desactivar un campus no lo invalida.
 * Inmutable.
 */
final readonly class AuthorizationSnapshot
{
    /**
     * @param  list<AssignmentScope>  $scopes
     */
    public function __construct(private array $scopes = []) {}

    /**
     * Los puestos con algún nombramiento, en el orden del enum.
     *
     * @return list<Role>
     */
    public function roles(): array
    {
        $held = array_map(fn (AssignmentScope $scope): Role => $scope->role, $this->scopes);

        return array_values(array_filter(Role::cases(), fn (Role $role): bool => in_array($role, $held, true)));
    }

    /**
     * Sus nombramientos, todos o los de `$role`.
     *
     * @return list<AssignmentScope>
     */
    public function scopes(?Role $role = null): array
    {
        return $role === null
            ? $this->scopes
            : array_values(array_filter($this->scopes, fn (AssignmentScope $scope): bool => $scope->role === $role));
    }

    /**
     * @return array{scopes: list<array{role: string, campus_id: ?string, org_unit_id: ?string, career_id: ?string, unit_is_department: bool}>}
     */
    public function toArray(): array
    {
        return ['scopes' => array_map(fn (AssignmentScope $scope): array => $scope->toArray(), $this->scopes)];
    }

    /**
     * @param  array{scopes?: list<array{role: string, campus_id?: ?string, org_unit_id?: ?string, career_id?: ?string, unit_is_department?: bool}>}  $data
     */
    public static function fromArray(array $data): self
    {
        return new self(array_values(array_filter(array_map(
            fn (array $scope): ?AssignmentScope => AssignmentScope::fromArray($scope),
            $data['scopes'] ?? [],
        ))));
    }
}
