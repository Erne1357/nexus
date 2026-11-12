<?php

namespace App\Modules\Core\Authorization;

use App\Modules\Core\Enums\Role;

/**
 * Un nombramiento vigente como dato plano, para el snapshot cacheado: el puesto y su alcance (nulo = sin límite en esa
 * dimensión). La modalidad (AZ-S17) va al final.
 */
final readonly class AssignmentScope
{
    public function __construct(
        public Role $role,
        public ?string $campusId,
        public ?string $unitId,
        public ?string $careerId,
        public ?string $modalityId,
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
            Dimension::Modality => $this->modalityId,
        };
    }

    /**
     * @return array{role: string, campus_id: ?string, org_unit_id: ?string, career_id: ?string, modality_id: ?string}
     */
    public function toArray(): array
    {
        return [
            'role' => $this->role->value,
            'campus_id' => $this->campusId,
            'org_unit_id' => $this->unitId,
            'career_id' => $this->careerId,
            'modality_id' => $this->modalityId,
        ];
    }

    /**
     * El nombramiento de un arreglo de toArray(), o null si no lo es: falta un campo, uno es de otro tipo o el puesto
     * ya no existe. Nunca completa un campo faltante, porque un alcance nulo es «sin límite» y ampliaría el acceso: uno
     * guardado antes de la modalidad (sin `modality_id`) se descarta.
     *
     * @param  array<array-key, mixed>  $data
     */
    public static function tryFromArray(array $data): ?self
    {
        $role = is_string($data['role'] ?? null) ? Role::tryFrom($data['role']) : null;

        if ($role === null) {
            return null;
        }

        foreach (['campus_id', 'org_unit_id', 'career_id', 'modality_id'] as $field) {
            if (! array_key_exists($field, $data) || ! ($data[$field] === null || is_string($data[$field]))) {
                return null;
            }
        }

        return new self($role, $data['campus_id'], $data['org_unit_id'], $data['career_id'], $data['modality_id']);
    }
}
