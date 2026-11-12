<?php

namespace App\Modules\Faculty\Queries;

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
    /**
     * Los que se invitan al validar: usuario activo sin contraseña, correo institucional y sin invitación vigente (nunca
     * invitados, o con la invitación ya vencida). Con `$teacherId`, solo ese docente, si cumple.
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
                ->where(fn (Builder $invited) => $invited
                    ->whereNull('access_invited_at')
                    ->orWhere('access_invited_at', '<=', now()->subDays(AccessInvitationLink::DAYS))))
            ->with('user')
            ->orderBy('rfc')
            ->get();
    }

    /**
     * Cuántos docentes sin acceso no recibirán su invitación, por qué: `email`, los de usuario activo sin contraseña a
     * los que les falta el correo institucional (al capturarlo, les llega); `user`, los demás sin acceso, que no tienen
     * usuario o lo tienen inactivo (lo resuelve el admin).
     *
     * @return array{email: int, user: int}
     */
    public function missingInvitations(string $departmentId): array
    {
        return [
            'email' => $this->ofDepartment($departmentId)
                ->whereNull('institutional_email')
                ->whereHas('user', fn (Builder $user) => $user->whereNull('password')->where('is_active', true))
                ->count(),
            'user' => $this->ofDepartment($departmentId)
                ->where(fn (Builder $query) => $query
                    ->whereNull('user_id')
                    ->orWhereHas('user', fn (Builder $user) => $user->whereNull('password')->where('is_active', false)))
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
