<?php

namespace App\Modules\Core\Models;

use App\Modules\Core\Database\Factories\UserFactory;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Notifications\RoutesMailAddress;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Attributes\UseFactory;
use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Notifications\Notification;

/**
 * Usuario de Nexus. Entra con `username` (RFC o de control del SII); el correo
 * es opcional. Sin contraseña no tiene acceso (AC-01): así nacen los de los docentes (AC-02) hasta que se les da
 * (UserAccess, AC-04). `password_origin` dice de dónde salió su contraseña (PasswordOrigin, AC-12): la del alumno es
 * su NIP del SII (AC-10). Un usuario nuevo debe cambiar su contraseña en el primer acceso (supuesto 2 de
 * PROJECT.md). Sus puestos son sus nombramientos (`role_assignments`, ADR-0018).
 * Qué puede ver o hacer se pregunta a Actor, nunca a los roles directamente. `authz_version` la sube un
 * trigger en cada cambio de sus nombramientos; `last_role` es el último perfil que usó (AZ-S4). `deactivated_at` y
 * `deactivated_by` dicen cuándo y quién dio de baja la cuenta en el panel (DeactivateUser, CF-05): mientras estén, solo
 * el admin la reactiva (CF-09).
 * Su correo de avisos es `email`, salvo que la notificación elija otro (RoutesMailAddress, CV11).
 */
#[Fillable(['username', 'name', 'email', 'password', 'password_origin', 'is_active', 'must_change_password', 'last_login_at'])]
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
            'password_origin' => PasswordOrigin::class,
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
            'last_login_at' => 'immutable_datetime',
            'access_invited_at' => 'immutable_datetime',
            'deactivated_at' => 'immutable_datetime',
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

    /**
     * A qué correo le llega una notificación: al que ella elige si implementa RoutesMailAddress (null: a ninguno); si
     * no, a `email`, como en Laravel.
     */
    public function routeNotificationForMail(?Notification $notification = null): ?string
    {
        return $notification instanceof RoutesMailAddress ? $notification->mailAddressFor($this) : $this->email;
    }
}
