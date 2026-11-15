<?php

namespace App\Modules\Faculty\Queries;

use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Rules\UserAccountRules;
use App\Modules\Core\Support\AccessInvitationLink;
use App\Modules\Faculty\Actions\InviteTeacherAccess;
use App\Modules\Faculty\Models\Teacher;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * Qué docentes de un departamento esperan su acceso (AC-09). Cuentan los docentes activos adscritos a él, sea o no la
 * principal, igual que en TeacherTermReadinessQuery.
 */
final class TeacherAccessQuery
{
    public function __construct(
        private readonly RoleAssignmentRules $rules,
        private readonly InvitationRelease $releases,
        private readonly UserAccountRules $accounts,
    ) {}

    /**
     * Por qué no se le puede mandar ahora la invitación de acceso a un docente desde su ficha (AC-07), o null si se
     * puede, en el orden en que se arregla: su usuario o el docente están inactivos, no tiene usuario o ya tiene
     * acceso, falta su correo institucional, tiene otro puesto (le da acceso el admin) o, si nunca se le invitó,
     * ningún departamento suyo ha validado su catálogo de docentes (InvitationRelease). Con una invitación vigente o
     * vencida se puede: el enlace nuevo reemplaza al anterior.
     */
    public function invitationBlocker(Teacher $teacher): ?string
    {
        $user = $teacher->user_id === null ? null : $teacher->user()->first(['id', 'is_active', 'password', 'access_invited_at']);

        return match (true) {
            $user === null => 'El docente no tiene usuario.',
            ! $user->is_active => 'Su usuario está inactivo.',
            ! $teacher->is_active || $teacher->trashed() => 'El docente está inactivo.',
            $user->password !== null => 'Ya tiene acceso.',
            $teacher->institutional_email === null => 'Falta su correo institucional: captúralo en «Editar datos».',
            $this->rules->holdsStaffRole($user->getKey()) => SendAccessInvitation::STAFF,
            $user->access_invited_at === null && ! $this->releases->isReleased($teacher) => InviteTeacherAccess::NOT_INVITED,
            default => null,
        };
    }

    /**
     * CF-07: por qué el admin no le manda ahora su invitación desde la ficha (InviteTeacherAccess::byAdmin()), o null si
     * se puede, en el orden en que se arregla. A diferencia de la del jefe, sale aunque tenga otro puesto y aunque su
     * departamento todavía no valide el catálogo de docentes.
     */
    public function adminInvitationBlocker(Teacher $teacher): ?string
    {
        $user = $this->userOf($teacher);

        return match (true) {
            $user === null => 'El docente no tiene usuario.',
            ! $user->is_active => 'Su usuario está inactivo.',
            ! $teacher->is_active || $teacher->trashed() => 'El docente está inactivo.',
            $user->password !== null => 'Ya tiene acceso.',
            $teacher->institutional_email === null => 'Falta su correo institucional: captúralo en «Editar datos».',
            default => $this->accounts->invitationBlocker($user, $teacher->institutional_email),
        };
    }

    /**
     * CF-08: por qué el admin no le manda desde la ficha el enlace para restablecer su contraseña (SendUserPasswordReset),
     * o null si se puede: va al correo de su usuario.
     */
    public function resetBlocker(Teacher $teacher): ?string
    {
        $user = $this->userOf($teacher);

        return $user === null ? 'El docente no tiene usuario.' : $this->accounts->resetBlocker($user);
    }

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

    private function userOf(Teacher $teacher): ?User
    {
        return $teacher->user_id === null ? null : User::query()->whereKey($teacher->user_id)->first();
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
