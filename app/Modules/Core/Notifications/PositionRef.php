<?php

namespace App\Modules\Core\Notifications;

use App\Modules\Core\Enums\Role;

/**
 * Un puesto al que compete un aviso (CE-02, ADR-0018): el rol y su alcance (unidad, carrera, campus, modalidad). Solo
 * guarda las columnas que el rol admite (Role::scopeColumns() y optionalScopeColumns()): el jefe de departamento es
 * su unidad, aunque el aviso traiga la modalidad; el coordinador, su carrera, su modalidad y, si el aviso lo dice, su
 * campus. Una columna nula es «todas». Solo lleva valores, así que viaja en un aviso en cola y se guarda en
 * `held_position_mails`.
 */
final readonly class PositionRef
{
    public const array COLUMNS = ['org_unit_id', 'career_id', 'campus_id', 'modality_id'];

    private function __construct(
        public Role $role,
        public ?string $orgUnitId,
        public ?string $careerId,
        public ?string $campusId,
        public ?string $modalityId,
    ) {}

    /**
     * El puesto `$role` sobre lo que diga el aviso; lo que el rol no admite se descarta.
     */
    public static function of(Role $role, ?string $unit = null, ?string $career = null, ?string $campus = null, ?string $modality = null): self
    {
        $allowed = self::columnsOf($role);
        $keep = fn (string $column, ?string $value): ?string => in_array($column, $allowed, true) && $value !== '' ? $value : null;

        return new self($role, $keep('org_unit_id', $unit), $keep('career_id', $career), $keep('campus_id', $campus), $keep('modality_id', $modality));
    }

    /**
     * Desde una fila con `role` y las columnas del alcance (`position_mailboxes`, `held_position_mails`).
     *
     * @param  array<string, mixed>  $row
     */
    public static function fromColumns(Role $role, array $row): self
    {
        return self::of($role, $row['org_unit_id'] ?? null, $row['career_id'] ?? null, $row['campus_id'] ?? null, $row['modality_id'] ?? null);
    }

    /**
     * Las columnas de alcance que admite el rol, en el orden de COLUMNS.
     *
     * @return list<string>
     */
    public static function columnsOf(Role $role): array
    {
        $columns = [...$role->scopeColumns(), ...$role->optionalScopeColumns()];

        return array_values(array_filter(self::COLUMNS, fn (string $column): bool => in_array($column, $columns, true)));
    }

    /**
     * @return array{org_unit_id: ?string, career_id: ?string, campus_id: ?string, modality_id: ?string}
     */
    public function columns(): array
    {
        return ['org_unit_id' => $this->orgUnitId, 'career_id' => $this->careerId, 'campus_id' => $this->campusId, 'modality_id' => $this->modalityId];
    }

    /**
     * Una llave estable del puesto, para no mandar dos correos al mismo buzón por un mismo aviso.
     */
    public function key(): string
    {
        return implode('|', [$this->role->value, ...array_map(fn (?string $value): string => $value ?? '*', array_values($this->columns()))]);
    }
}
