<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Authorization\ScopeLabels;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\Campus;
use App\Modules\Core\Models\Modality;
use App\Modules\Core\Models\OrgUnit;
use App\Modules\Core\Models\PositionMailbox;
use App\Modules\Core\Notifications\InstitutionalMail;
use App\Modules\Core\Notifications\PositionRef;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

/**
 * Las reglas de un buzón de puesto (CE-02), que comparten capturarlo y editarlo (SavePositionMailbox). Cada regla rota es
 * una ValidationException sobre su campo.
 *
 * - `role`: un puesto con alcance (no el admin, el docente ni el alumno, cuyos avisos son de persona).
 * - El alcance: solo las columnas que el puesto admite (PositionRef::columnsOf()); cada una es opcional, y vacía es
 *   «todas» (comodín). La unidad tiene que ser una que el puesto admite, como al nombrar (RoleAssignmentRules); la
 *   carrera, el campus y la modalidad tienen que existir.
 * - `email`: un correo institucional (InstitutionalMail, CE-01), en minúsculas.
 * - Un solo buzón activo por puesto y alcance exactos (también lo cuida el índice `position_mailboxes_one_active`).
 */
final class PositionMailboxRules
{
    public function __construct(private readonly RoleAssignmentRules $assignments, private readonly ScopeLabels $labels) {}

    /**
     * El puesto y su alcance normalizados: el rol como enum y cada columna como uuid o null.
     *
     * @param  array<string, mixed>  $data
     * @return array{role: Role, org_unit_id: ?string, career_id: ?string, campus_id: ?string, modality_id: ?string}
     *
     * @throws ValidationException
     */
    public function position(array $data): array
    {
        $role = Role::tryFrom((string) ($data['role'] ?? ''));

        if ($role === null || PositionRef::columnsOf($role) === []) {
            throw ValidationException::withMessages(['role' => 'Elige un puesto que tenga alcance: jefatura, coordinación, subdirección o personal de área.']);
        }

        $allowed = PositionRef::columnsOf($role);
        $scope = [];

        foreach (PositionRef::COLUMNS as $column) {
            $value = trim((string) ($data[$column] ?? ''));

            if ($value === '') {
                $scope[$column] = null;

                continue;
            }

            if (! in_array($column, $allowed, true)) {
                throw ValidationException::withMessages([$this->field($column) => "El puesto {$role->label()} no lleva {$this->name($column)}."]);
            }

            if (! Str::isUuid($value) || ! $this->exists($role, $column, $value)) {
                throw ValidationException::withMessages([$this->field($column) => "Elige {$this->article($column)} de la lista."]);
            }

            $scope[$column] = $value;
        }

        return ['role' => $role, ...$scope];
    }

    /**
     * @throws ValidationException sobre `email` si no es un correo institucional
     */
    public function email(?string $email): string
    {
        $email = mb_strtolower(trim((string) $email));

        if (! InstitutionalMail::isInstitutional($email)) {
            $domains = implode(' o @', InstitutionalMail::domains());

            throw ValidationException::withMessages(['email' => "El buzón tiene que ser un correo institucional (@{$domains})."]);
        }

        return $email;
    }

    /**
     * @param  array{role: Role, org_unit_id: ?string, career_id: ?string, campus_id: ?string, modality_id: ?string}  $position
     *
     * @throws ValidationException sobre `role` si ese puesto y alcance ya tienen otro buzón activo
     */
    public function ensureFree(array $position, ?PositionMailbox $except = null): void
    {
        $query = PositionMailbox::query()->active()->where('role', $position['role']);

        foreach (PositionRef::COLUMNS as $column) {
            $position[$column] === null ? $query->whereNull($column) : $query->where($column, $position[$column]);
        }

        $taken = $query->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))->value('email');

        if ($taken !== null) {
            throw $this->taken($taken);
        }
    }

    public function taken(string $email): ValidationException
    {
        return ValidationException::withMessages(['role' => "Ese puesto ya tiene un buzón activo ({$email}): edítalo o desactívalo primero."]);
    }

    private function exists(Role $role, string $column, string $id): bool
    {
        return match ($column) {
            'org_unit_id' => ($unit = OrgUnit::query()->whereKey($id)->first()) !== null && $this->assignments->allowsUnit($role, $unit),
            'career_id' => $this->labels->careers([$id]) !== [],
            'campus_id' => Campus::query()->whereKey($id)->exists(),
            'modality_id' => Modality::query()->whereKey($id)->exists(),
        };
    }

    private function field(string $column): string
    {
        return match ($column) {
            'org_unit_id' => 'unit',
            'career_id' => 'career',
            'campus_id' => 'campus',
            'modality_id' => 'modality',
        };
    }

    private function name(string $column): string
    {
        return match ($column) {
            'org_unit_id' => 'unidad',
            'career_id' => 'carrera',
            'campus_id' => 'campus',
            'modality_id' => 'modalidad',
        };
    }

    private function article(string $column): string
    {
        return match ($column) {
            'org_unit_id' => 'una unidad',
            'career_id' => 'una carrera',
            'campus_id' => 'un campus',
            'modality_id' => 'una modalidad',
        };
    }
}
