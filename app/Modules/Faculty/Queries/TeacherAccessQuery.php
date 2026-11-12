<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qué docentes de un departamento esperan su acceso (AC-09). Cuentan los docentes activos adscritos a él, sea o no la
 * principal, igual que en TeacherTermReadinessQuery.
 */
final class TeacherAccessQuery
{
    public function __construct(private readonly RoleAssignmentRules $rules) {}

    /**
     * Los que se invitan al validar: usuario activo sin contraseña y sin otro puesto vigente o por empezar (a los jefes
     * los habilita el admin, AC-07), correo institucional y sin invitación vigente (nunca invitados, o con la invitación
     * ya vencida). Con `$teacherId`, solo ese docente, si cumple.
     *
     * @return Collection<int, Teacher>
     */
    public function awaitingInvitation(string $departmentId, ?string $teacherId = null): Collection
    {
        return $this->ofDepartment($departmentId)
            ->when($teacherId !== null, fn (Builder $query) => $query->whereKey($teacherId))
            ->whereNotNull('institutional_email')
            ->whereHas('user', fn (Builder $user) => $user
                ->whereNull('password')
                ->where('is_active', true)
                ->whereNotIn('users.id', $this->rules->staffUserIds())
                ->where(fn (Builder $invited) => $invited
                    ->whereNull('access_invited_at')
                    ->orWhere('access_invited_at', '<=', now()->subDays(AccessInvitationLink::DAYS))))
            ->orderBy('rfc')
            ->get();
    }

    /**
     * Cuántos docentes sin acceso no recibirán su invitación, por qué: `email`, los de usuario activo sin contraseña y
     * sin otro puesto a los que les falta el correo institucional (al capturarlo, les llega); `user`, los demás sin
     * acceso, que no tienen usuario, lo tienen inactivo o tienen otro puesto (les da acceso el admin).
     *
     * @return array{email: int, user: int}
     */
    public function missingInvitations(string $departmentId): array
    {
        return [
            'email' => $this->ofDepartment($departmentId)
                ->whereNull('institutional_email')
                ->whereHas('user', fn (Builder $user) => $user
                    ->whereNull('password')
                    ->where('is_active', true)
                    ->whereNotIn('users.id', $this->rules->staffUserIds()))
                ->count(),
            'user' => $this->ofDepartment($departmentId)
                ->where(fn (Builder $query) => $query
                    ->whereNull('user_id')
                    ->orWhereHas('user', fn (Builder $user) => $user
                        ->whereNull('password')
                        ->where(fn (Builder $blocked) => $blocked
                            ->where('is_active', false)
                            ->orWhereIn('users.id', $this->rules->staffUserIds()))))
                ->count(),
        ];
    }

    /**
     * @return Builder<Teacher>
     */
    private function ofDepartment(string $departmentId): Builder
    {
        return Teacher::query()
            ->where('teachers.is_active', true)
            ->whereHas('adscriptions', fn (Builder $adscription) => $adscription->where('department_id', $departmentId));
    }
}
