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
     * Null si el puesto ya no existe (un snapshot cacheado antes de un despliegue que lo quitó): no cuenta.
     *
     * @param  array{role: string, campus_id?: ?string, org_unit_id?: ?string, career_id?: ?string, unit_is_department?: bool}  $data
     */
    public static function fromArray(array $data): ?self
    {
        $role = Role::tryFrom($data['role']);

        return $role === null ? null : new self(
            $role,
            $data['campus_id'] ?? null,
            $data['org_unit_id'] ?? null,
            $data['career_id'] ?? null,
            $data['unit_is_department'] ?? false,
        );
    }
}
