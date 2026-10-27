<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Spatie\Permission\Traits\HasRoles;

/**
 * Usuario de Nexus. Entra con `username` (número de empleado o de control del SII); el correo
 * es opcional. Un usuario nuevo debe cambiar su contraseña en el primer acceso (supuesto 2 de
 * PROJECT.md). Los roles son de spatie/permission y el alcance de cada rol vive en `role_scopes`;
 * qué puede ver o hacer se pregunta a ScopedContext, nunca a los roles directamente.
 */
#[Fillable(['username', 'name', 'email', 'password', 'is_active', 'must_change_password', 'last_login_at'])]
#[Hidden(['password', 'remember_token'])]
#[UseFactory(UserFactory::class)]
class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, HasUuids, Notifiable;

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
        ];
    }

    /**
     * Todos sus alcances, vigentes o no; los vigentes con ->current().
     *
     * @return HasMany<RoleScope, $this>
     */
    public function roleScopes(): HasMany
    {
        return $this->hasMany(RoleScope::class);
    }
}
