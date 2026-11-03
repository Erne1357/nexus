<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;

/**
 * Un nombramiento vigente como dato plano, para el snapshot cacheado: el puesto y su alcance (nulo = sin límite en esa
 * dimensión). `unitIsDepartment` dice si su unidad es un departamento académico; solo lo usa la API de compatibilidad
 * de ScopedContext (un objetivo sin departamento), que se va en 3.P9.
 */
final readonly class AssignmentScope
{
    public function __construct(
        public Role $role,
        public ?string $campusId,
        public ?string $unitId,
        public ?string $careerId,
        public bool $unitIsDepartment = false,
    ) {}

    /**
     * El valor del nombramiento en la dimensión; null si no la fija.
     */
    public function value(Dimension $dimension): ?string
    {
        return match ($dimension) {
            Dimension::Campus => $this->campusId,
            Dimension::Unit => $this->unitId,
            Dimension::Career => $this->careerId,
        };
    }

    /**
     * @return array{role: string, campus_id: ?string, org_unit_id: ?string, career_id: ?string, unit_is_department: bool}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'campus_id' => $this->campusId,
            'org_unit_id' => $this->unitId,
            'career_id' => $this->careerId,
            'unit_is_department' => $this->unitIsDepartment,
        ];
    }

    /**
     * El nombramiento de un arreglo de toArray(), o null si no lo es: falta un campo, uno es de otro tipo o el puesto
     * ya no existe. Nunca completa un campo faltante, porque un alcance nulo es «sin límite» y ampliaría el acceso.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $role = is_string($data['role'] ?? null) ? Role::tryFrom($data['role']) : null;

        if ($role === null || ! is_bool($data['unit_is_department'] ?? null)) {
            return null;
        }

        foreach (['campus_id', 'org_unit_id', 'career_id'] as $field) {
            if (! array_key_exists($field, $data) || ! ($data[$field] === null || is_string($data[$field]))) {
                return null;
            }
        }

        return new self($role, $data['campus_id'], $data['org_unit_id'], $data['career_id'], $data['unit_is_department']);
    }
}
