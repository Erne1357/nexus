<?php

namespace App\Modules\Students\Actions;

use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Core\Enums\Role;
use App\Modules\Core\Models\User;
use App\Modules\Core\Rules\RoleAssignmentRules;
use App\Modules\Core\Support\SiiNip;
use App\Modules\Students\Enums\StudentStatus;
use App\Modules\Students\Models\Student;
use App\Modules\Students\Rules\InstitutionalEmail;
use Illuminate\Database\QueryException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;
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
 * - Activo solo si el alumno es vigente: estatus `activo` (ACT, INS) o `baja_temporal` (BT*), como cuenta la demanda,
 *   y no está borrado. Se decide por el estatus y no por `students.is_active`, que el motor de importación no cambia
 *   en un alumno que ya existe. Al volverse vigente se activa y al dejar de serlo se desactiva (supuestos por
 *   confirmar), salvo que el usuario tenga un puesto de personal vigente o por empezar (el admin incluido,
 *   RoleAssignmentRules::holdsStaffRole()): ese se queda activo y se reporta, como en la baja del docente (AZ-S12).
 * - Su contraseña es el NIP (bcrypt, SiiNip::hash(), con ceros a 4 dígitos si es corto: AC-11) mientras el usuario
 *   no tenga contraseña o siga con la del SII (`password_origin = sii_nip`); si ya la cambió por una propia o el admin
 *   le dio la por defecto, no se toca (AC-12, supuesto por confirmar). Un NIP vacío deja sin acceso a quien todavía
 *   no tiene contraseña (AC-01), pero no le quita el suyo del SII a quien ya lo tiene: lo reporta (supuesto por
 *   confirmar), porque una exportación que perdió los NIP dejaría fuera a todos los alumnos.
 * - Le da el puesto `alumno`, sin alcance, si no lo tiene.
 *
 * Cada usuario que cambia deja su bitácora (qué cambió, nunca el NIP ni el hash), y el bloque, una entrada con las
 * cuentas y los números de control en conflicto, con el correo de otro usuario, que conservaron su NIP porque el nuevo
 * llegó vacío o que siguen activos por su puesto, a nombre de `$actor`.
 *
 * Si una escritura falla, lanza un RuntimeException con un mensaje fijo y el SQLSTATE, sin encadenar la QueryException:
 * su mensaje trae los valores de la consulta, entre ellos el hash del NIP, y acabaría en el log y en `failed_jobs`.
 */
final class SyncStudentAccounts
{
    /** Los estatus de un alumno vigente: los mismos que cuenta la demanda (SubjectDemandQuery). */
    private const array CURRENT = [StudentStatus::Activo, StudentStatus::BajaTemporal];

    public function __construct(private readonly RoleAssignmentRules $rules) {}

    /**
     * @param  array<string, string>  $nips  el NIP del SII por número de control; '' si el SII no trae
     * @return array{created: int, updated: int, unchanged: int, missing: int, conflicts: list<string>, emails_taken: list<string>, nips_kept: list<string>, kept_active: list<string>}
     */
    public function handle(#[\SensitiveParameter] array $nips, ?User $actor = null): array
    {
        $summary = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'missing' => 0, 'conflicts' => [], 'emails_taken' => [], 'nips_kept' => [], 'kept_active' => []];

        foreach ($nips as $controlNumber => $nip) {
            $controlNumber = (string) $controlNumber;
            [$result, $flags] = $this->sync($controlNumber, $nip, $actor);

            if ($result === 'conflict') {
                $summary['conflicts'][] = $controlNumber;
            } else {
                $summary[$result]++;
            }

            foreach ($flags as $flag) {
                $summary[$flag][] = $controlNumber;
            }
        }

        $total = count($nips);

        activity()
            ->causedBy($actor)
            ->event('student-accounts-synced')
            ->withProperties($summary)
            ->log("Sincronizó las cuentas de {$total} alumnos: {$summary['created']} creadas, {$summary['updated']} puestas al día, "
                ."{$summary['unchanged']} sin cambios, {$summary['missing']} sin alumno en Nexus, ".count($summary['conflicts']).' en conflicto, '
                .count($summary['emails_taken']).' sin correo porque otro usuario lo tiene, '
                .count($summary['nips_kept']).' que conservaron su NIP porque el nuevo llegó vacío y '
                .count($summary['kept_active']).' que siguen activas por un puesto de personal');

        return $summary;
    }

    /**
     * @return array{0: 'created'|'updated'|'unchanged'|'missing'|'conflict', 1: list<'emails_taken'|'nips_kept'|'kept_active'>}
     *
     * @throws RuntimeException si una escritura falla, sin los valores de la consulta
     */
    private function sync(string $controlNumber, #[\SensitiveParameter] string $nip, ?User $actor): array
    {
        try {
            return $this->write($controlNumber, $nip, $actor);
        } catch (QueryException $exception) {
            throw new RuntimeException('No se pudo escribir la cuenta de un alumno (SQLSTATE '.$exception->getCode().').');
        }
    }

    /**
     * @return array{0: 'created'|'updated'|'unchanged'|'missing'|'conflict', 1: list<'emails_taken'|'nips_kept'|'kept_active'>}
     */
    private function write(string $controlNumber, #[\SensitiveParameter] string $nip, ?User $actor): array
    {
        return DB::transaction(function () use ($controlNumber, $nip, $actor): array {
            $student = Student::withTrashed()->where('control_number', $controlNumber)->lockForUpdate()->first();

            if ($student === null) {
                return ['missing', []];
            }

            $user = $student->user_id === null ? null : User::query()->lockForUpdate()->find($student->user_id);
            $creating = $user === null;

            if ($creating) {
                if (User::query()->where('username', $controlNumber)->exists()) {
                    return ['conflict', []];
                }

                $user = (new User)->forceFill(['username' => $controlNumber, 'must_change_password' => false]);
            }

            $flags = array_values(array_filter([
                $this->fillActive($user, $student) ? null : 'kept_active',
                $this->fillEmail($user, $controlNumber) ? 'emails_taken' : null,
                $this->fillPassword($user, $nip === '' ? null : SiiNip::normalize($nip)) ? null : 'nips_kept',
            ]));
            $user->forceFill(['name' => $student->name]);

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

                return ['created', $flags];
            }

            if ($changes === [] && ! $credential && ! $roleGiven) {
                return ['unchanged', $flags];
            }

            $this->logUpdated($user, $actor, $changes, $credential, $roleGiven);

            return ['updated', $flags];
        });
    }

    /**
     * Activo si el alumno es vigente y no está borrado. Devuelve false si no lo desactivó porque tiene un puesto de
     * personal.
     */
    private function fillActive(User $user, Student $student): bool
    {
        $current = ! $student->trashed() && in_array($student->status, self::CURRENT, true);

        if (! $current && $user->exists && $user->is_active && $this->rules->holdsStaffRole($user->getKey())) {
            return false;
        }

        $user->forceFill(['is_active' => $current]);

        return true;
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
     * Devuelve false si el NIP llegó vacío y el usuario conservó el suyo del SII.
     */
    private function fillPassword(User $user, #[\SensitiveParameter] ?string $nip): bool
    {
        if ($user->password !== null && $user->password_origin !== PasswordOrigin::SiiNip) {
            return true;
        }

        if ($nip === null) {
            return $user->password === null;
        }

        if ($user->password === null || ! Hash::check($nip, $user->password)) {
            $user->forceFill(['password' => SiiNip::hash($nip), 'password_origin' => PasswordOrigin::SiiNip]);
        }

        return true;
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
            $credential ? 'su NIP del SII' : null,
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
