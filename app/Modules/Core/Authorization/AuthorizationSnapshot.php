<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;
use UnexpectedValueException;

/**
 * Lo que una persona tiene (spec 7 y 9): sus nombramientos vigentes hoy que llenan lo que su puesto pide
 * (Role::scopeColumns), el de admin incluido, como datos planos que se guardan en caché. No sabe qué campus ni qué
 * modalidades están activos: ese filtro lo aplica Actor en cada petición, así que desactivar uno no lo invalida.
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
     * @return array{scopes: list<array{role: string, campus_id: ?string, org_unit_id: ?string, career_id: ?string, modality_id: ?string}>}
     */
    public function toArray(): array
    {
        return ['scopes' => array_map(fn (AssignmentScope $scope): array => $scope->toArray(), $this->scopes)];
    }

    /**
     * El snapshot de un arreglo de toArray(); lanza UnexpectedValueException si no lo es (ver tryFromArray()).
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function fromArray(array $data): self
    {
        return self::tryFromArray($data)
            ?? throw new UnexpectedValueException('El arreglo no es un snapshot de nombramientos: le falta un campo o uno es de otro tipo.');
    }

    /**
     * El snapshot de un arreglo de toArray(), o null si cualquier parte no lo es: un dato cacheado incompleto se
     * descarta entero y se vuelve a leer de la base, nunca se completa.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $rows = $data['scopes'] ?? null;

        if (! is_array($rows) || ! array_is_list($rows)) {
            return null;
        }

        $scopes = [];

        foreach ($rows as $row) {
            $scope = is_array($row) ? AssignmentScope::tryFromArray($row) : null;

            if ($scope === null) {
                return null;
            }

            $scopes[] = $scope;
        }

        return new self($scopes);
    }
}
