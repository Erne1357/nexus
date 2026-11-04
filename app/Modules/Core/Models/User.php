<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\UserFactory;
use App\Modules\Core\Enums\Role;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * Usuario de Nexus. Entra con `username` (número de empleado o de control del SII); el correo
 * es opcional. Un usuario nuevo debe cambiar su contraseña en el primer acceso (supuesto 2 de
 * PROJECT.md). Sus puestos son sus nombramientos (`role_assignments`, ADR-0018).
 * Qué puede ver o hacer se pregunta a Actor, nunca a los roles directamente. `authz_version` la sube un
 * trigger en cada cambio de sus nombramientos; `last_role` es el último perfil que usó (AZ-S4).
 */
#[Fillable(['username', 'name', 'email', 'password', 'is_active', 'must_change_password', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasUuids, Notifiable;

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'last_role' => Role::class,
            'authz_version' => 'integer',
        ];
    }

    /**
     * Todos sus nombramientos, vigentes o no; los vigentes con ->current().
     *
     * @return HasMany<RoleAssignment, $this>
     */
    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }
}
