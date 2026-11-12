<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Support\SiiNip;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\InstitutionalEmail;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Pone de acuerdo el usuario de cada alumno de un bloque con su NIP del SII (AC-10 a AC-13). La corre la migración
 * del SII en la cola, por bloques (SyncStudentAccountsJob); es idempotente: correrla otra vez con los mismos datos no
 * escribe nada (y no cierra sesiones, porque no cambia el hash de nadie).
 *
 * Por cada número de control, en su propia transacción y con el alumno y su usuario bloqueados:
 * - Sin alumno en Nexus (su renglón no se migró), no hace nada.
 * - Sin usuario ligado, lo crea: username = número de control, el nombre del alumno, su correo institucional (AC-13)
 *   si ningún otro usuario lo tiene, sin cambio obligatorio, y lo liga (`students.user_id`). Si ya existe un usuario
 *   con ese username que no es suyo, no toca nada y lo reporta como conflicto.
 * - Activo solo si el alumno es vigente (`students.is_active`: ACT, INS y bajas temporales) y no está borrado; al
 *   volverse vigente se activa y al dejar de serlo se desactiva (supuestos por confirmar).
 * - Su contraseña es el NIP (bcrypt, SiiNip::hash(), con ceros a 4 dígitos si es corto: AC-11) mientras el usuario
 *   no tenga contraseña o siga con la del SII (`password_origin = sii_nip`); si ya la cambió por una propia o el admin
 *   le dio la por defecto, no se toca (AC-12, supuesto por confirmar). Un NIP vacío lo deja sin acceso (AC-01).
 * - Le da el puesto `alumno`, sin alcance, si no lo tiene.
 *
 * Cada usuario que cambia deja su bitácora (qué cambió, nunca el NIP ni el hash), y el bloque, una entrada con las
 * cuentas y los números de control en conflicto o con el correo de otro usuario, a nombre de `$actor`.
 */
final class SyncStudentAccounts
{
    /**
     * @param  array<string, string>  $nips  el NIP del SII por número de control; '' si el SII no trae
     * @return array{created: int, updated: int, unchanged: int, missing: int, conflicts: list<string>, emails_taken: list<string>}
     */
    public function handle(#[\SensitiveParameter] array $nips, ?User $actor = null): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'conflicts' => [], 'emails_taken' => []];

        foreach ($nips as $controlNumber => $nip) {
            [$result, $emailTaken] = $this->sync((string) $controlNumber, $nip, $actor);

            if ($result === 'conflict') {
                $summary['conflicts'][] = (string) $controlNumber;
            } else {
                $summary[$result]++;
            }

            if ($emailTaken) {
                $summary['emails_taken'][] = (string) $controlNumber;
            }
        }

        $total = count($nips);

        activity()
            ->causedBy($actor)
            ->event('student-accounts-synced')
            ->withProperties($summary)
            ->log("Sincronizó las cuentas de {$total} alumnos: {$summary['created']} creadas, {$summary['updated']} puestas al día, "
                ."{$summary['unchanged']} sin cambios, {$summary['missing']} sin alumno en Nexus, ".count($summary['conflicts']).' en conflicto y '
                .count($summary['emails_taken']).' sin correo porque otro usuario lo tiene');

        return $summary;
    }

    /**
     * @return array{0: 'created'|'updated'|'unchanged'|'missing'|'conflict', 1: bool}
     */
    private function sync(string $controlNumber, #[\SensitiveParameter] string $nip, ?User $actor): array
    {
        return DB::transaction(function () use ($controlNumber, $nip, $actor): array {
            $student = Student::withTrashed()->where('control_number', $controlNumber)->lockForUpdate()->first();

            if ($student === null) {
                return ['missing', false];
            }

            $user = $student->user_id === null ? null : User::query()->lockForUpdate()->find($student->user_id);
            $creating = $user === null;

            if ($creating) {
                if (User::query()->where('username', $controlNumber)->exists()) {
                    return ['conflict', false];
                }

                $user = (new User)->forceFill(['username' => $controlNumber, 'must_change_password' => false]);
            }

            $user->forceFill(['name' => $student->name, 'is_active' => $student->is_active && ! $student->trashed()]);
            $emailTaken = $this->fillEmail($user, $controlNumber);
            $this->fillPassword($user, $nip === '' ? null : SiiNip::normalize($nip));

            $changes = Arr::only($user->getDirty(), ['name', 'email', 'is_active']);
            $credential = $user->isDirty('password');
            $user->save();

            if ($student->user_id === null) {
                $student->forceFill(['user_id' => $user->getKey()])->save();
            }

            $roleGiven = ! $user->roleAssignments()->where('role', Role::Alumno)->notEnded()->exists();

            if ($roleGiven) {
                $user->roleAssignments()->create(['role' => Role::Alumno, 'valid_from' => today()->toDateString()]);
            }

            if ($creating) {
                $this->logCreated($user, $actor);

                return ['created', $emailTaken];
            }

            if ($changes === [] && ! $credential && ! $roleGiven) {
                return ['unchanged', $emailTaken];
            }

            $this->logUpdated($user, $actor, $changes, $credential, $roleGiven);

            return ['updated', $emailTaken];
        });
    }

    /**
     * Pone el correo institucional si el usuario no tiene correo; devuelve si no pudo porque otro usuario lo tiene.
     */
    private function fillEmail(User $user, string $controlNumber): bool
    {
        $email = InstitutionalEmail::of($controlNumber);

        if ($user->email !== null || $email === null) {
            return false;
        }

        $taken = User::query()
            ->whereRaw('lower(email) = ?', [$email])
            ->when($user->exists, fn ($query) => $query->whereKeyNot($user->getKey()))
            ->exists();

        if (! $taken) {
            $user->forceFill(['email' => $email]);
        }

        return $taken;
    }

    /**
     * El NIP ya normalizado, o nulo si el SII no trae. Solo si el usuario no tiene contraseña o sigue con la del SII.
     */
    private function fillPassword(User $user, #[\SensitiveParameter] ?string $nip): void
    {
        if ($user->password !== null && $user->password_origin !== PasswordOrigin::SiiNip) {
            return;
        }

        if ($nip === null) {
            $user->forceFill(['password' => null, 'password_origin' => null]);

            return;
        }

        if ($user->password === null || ! Hash::check($nip, $user->password)) {
            $user->forceFill(['password' => SiiNip::hash($nip), 'password_origin' => PasswordOrigin::SiiNip]);
        }
    }

    private function logCreated(User $user, ?User $actor): void
    {
        activity()
            ->performedOn($user)
            ->causedBy($actor)
            ->event(ActivityEvent::Created)
            ->withChanges(['attributes' => $user->only(['username', 'name', 'email', 'is_active'])])
            ->log("Dio de alta al usuario del alumno {$user->username}".($user->password === null ? ', sin acceso' : ', con su NIP del SII'));
    }

    /**
     * @param  array<string, mixed>  $changes
     */
    private function logUpdated(User $user, ?User $actor, array $changes, bool $credential, bool $roleGiven): void
    {
        $done = array_filter([
            isset($changes['is_active']) ? ($user->is_active ? 'lo activó' : 'lo desactivó') : null,
            isset($changes['name']) ? 'su nombre' : null,
            isset($changes['email']) ? 'su correo institucional' : null,
            $credential ? ($user->password === null ? 'lo dejó sin acceso' : 'su NIP del SII') : null,
            $roleGiven ? 'el puesto de alumno' : null,
        ]);

        activity()
            ->performedOn($user)
            ->causedBy($actor)
            ->event(ActivityEvent::Updated)
            ->withChanges(['attributes' => $changes, 'old' => Arr::only($user->getPrevious(), array_keys($changes))])
            ->log("Puso al día el usuario del alumno {$user->username}: ".implode(', ', $done));
    }
}
