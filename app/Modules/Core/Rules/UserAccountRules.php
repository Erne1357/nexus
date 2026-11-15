<?php

namespace App\Modules\Core\Rules;

use App\Modules\Core\Models\User;
use Illuminate\Validation\ValidationException;

/**
 * Reglas de las cuentas que el admin maneja en «Personas y puestos» (tarea 1.22-C1), compartidas por las Actions y la
 * pantalla, que con ellas deshabilita cada acción con su motivo:
 *
 * - CF-S3: el correo institucional es opcional, va en minúsculas, es único sin importar mayúsculas y solo vale del
 *   dominio `nexus.institutional_email_domain` (la misma forma que AC-06).
 * - CF-S4 (supuesto por confirmar): la cuenta de un alumno no se edita, no se da de baja ni se reactiva en el panel
 *   (RoleAssignmentRules::isStudentAccount()); solo se le restablece la contraseña. Una que dio de baja el panel no
 *   cuenta como de alumno.
 * - CF-06 (supuesto por confirmar): solo se reactiva una cuenta que dio de baja el panel.
 * - CF-05: nadie se da de baja a sí mismo (supuesto por confirmar) y no se da de baja a quien dejaría algún día sin admin
 *   vigente (AZ-S12, RoleAssignmentRules::deactivationGap()).
 * - CF-08: el restablecimiento sigue AC-16 (PasswordResetRules) sin pedir el correo confirmado; `resetBlocker()` dice
 *   por qué no.
 * - CF-07: el admin invita a una cuenta activa, sin contraseña y con correo institucional, tenga o no un puesto;
 *   `invitationBlocker()` dice por qué no.
 */
final class UserAccountRules
{
    public const string STUDENT = 'Es alumno: su cuenta se activa y se desactiva con su estatus en el SII.';

    public const string SELF = 'No puedes darte de baja a ti mismo.';

    public const string ALREADY_INACTIVE = 'La cuenta ya está dada de baja.';

    public const string ALREADY_ACTIVE = 'La cuenta ya está activa.';

    public const string FROM_TEACHERS = 'Se reactiva desde Docentes.';

    public const string NOT_FROM_PANEL = 'No la dio de baja el panel: no se reactiva aquí.';

    public const string ACTOR_INACTIVE = 'Tu cuenta ya no está activa.';

    public const string RESET_INACTIVE = PasswordResetRules::INACTIVE;

    public const string RESET_NO_PASSWORD = PasswordResetRules::NO_PASSWORD;

    public const string RESET_NO_EMAIL = PasswordResetRules::NO_EMAIL;

    public const string RESET_RECENT = 'Ya se le mandó un enlace hace menos de un minuto.';

    public const string INVITE_INACTIVE = PasswordResetRules::INACTIVE;

    public const string INVITE_HAS_ACCESS = 'Ya tiene acceso.';

    public const string INVITE_NO_EMAIL = 'Captura su correo institucional.';

    /**
     * Lo que ya respondió isStudent() para cada usuario: la ficha pregunta varias veces por el mismo en un render. Vive
     * lo que vive esta instancia, que el contenedor crea en cada resolución (no es singleton).
     *
     * @var array<string, bool>
     */
    private array $students = [];

    public function __construct(
        private readonly RoleAssignmentRules $assignments,
        private readonly PasswordResetRules $passwordReset,
    ) {}

    /**
     * El correo institucional normalizado: sin espacios y en minúsculas; vacío es ninguno.
     *
     * @throws ValidationException sobre `email` si no es un correo del dominio institucional
     */
    public function institutionalEmail(?string $email): ?string
    {
        $email = mb_strtolower(trim((string) $email));

        if ($email === '') {
            return null;
        }

        $domain = mb_strtolower((string) config('nexus.institutional_email_domain'));

        if ($domain === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false || ! str_ends_with($email, "@{$domain}")) {
            throw ValidationException::withMessages(['email' => "El correo institucional debe terminar en @{$domain}."]);
        }

        return $email;
    }

    /**
     * @throws ValidationException sobre `email` si otro usuario ya lo tiene, sin importar mayúsculas
     */
    public function ensureEmailIsFree(string $email, ?User $except = null): void
    {
        $taken = User::query()
            ->whereRaw('lower(email) = ?', [mb_strtolower($email)])
            ->when($except !== null, fn ($query) => $query->whereKeyNot($except->getKey()))
            ->exists();

        if ($taken) {
            throw $this->emailTaken($email);
        }
    }

    public function emailTaken(string $email): ValidationException
    {
        return ValidationException::withMessages(['email' => 'Otro usuario ya tiene el correo '.mb_strtolower($email).'.']);
    }

    /**
     * CF-S4: si la cuenta es de un alumno para el panel. Una que dio de baja el panel (`deactivated_at`) nunca lo es: la
     * baja pudo quitar el puesto del personal que todavía no empezaba y dejarle solo el de alumno, pero el panel la dio de
     * baja y el panel la reactiva.
     */
    public function isStudent(User $user): bool
    {
        if ($user->deactivated_at !== null) {
            return false;
        }

        return $this->students[$user->getKey()] ??= $this->assignments->isStudentAccount($user);
    }

    /**
     * Por qué no se editan el nombre y el correo; nulo si sí.
     */
    public function updateBlocker(User $user): ?string
    {
        return $this->isStudent($user) ? self::STUDENT : null;
    }

    /**
     * Por qué `$actor` no da de baja a `$user` (CF-05, CF-S4); nulo si sí. El último admin se revisa con `adminGap()`,
     * que lee la cobertura: quien escribe lo pregunta bajo RoleAssignmentRules::ADMIN_SEATS_LOCK.
     */
    public function deactivationBlocker(User $user, User $actor): ?string
    {
        return $this->deactivationStateBlocker($user, $actor) ?? $this->adminGap($user);
    }

    /**
     * Lo que no depende de los demás admin: uno mismo, un alumno o una cuenta ya dada de baja.
     */
    public function deactivationStateBlocker(User $user, User $actor): ?string
    {
        return match (true) {
            $user->is($actor) => self::SELF,
            $this->isStudent($user) => self::STUDENT,
            ! $user->is_active => self::ALREADY_INACTIVE,
            default => null,
        };
    }

    /**
     * AZ-S12: si dar de baja a `$user` dejaría algún día sin admin vigente, el texto con ese día; nulo si no. Hoy cuenta:
     * la cuenta inactiva deja de cubrir desde que se da de baja.
     */
    public function adminGap(User $user): ?string
    {
        $day = $this->assignments->deactivationGap($user);

        if ($day === null) {
            return null;
        }

        return $day->isToday()
            ? 'Es el último admin vigente hoy: nombra a otra persona antes de darlo de baja.'
            : 'El '.$day->format('d/m/Y').' nadie tendría un nombramiento de Administración vigente: nombra a otra persona antes de darlo de baja.';
    }

    /**
     * Por qué no se reactiva (CF-06, CF-S4); nulo si sí. Solo se reactiva una cuenta que dio de baja el panel
     * (`deactivated_at`, supuesto por confirmar): la de un docente inactivo la desactivó Faculty y se reactiva en
     * Docentes, la de un alumno sigue su estatus, y otra que esté inactiva no la dio de baja el panel.
     */
    public function reactivationBlocker(User $user): ?string
    {
        return match (true) {
            $user->is_active => self::ALREADY_ACTIVE,
            $user->deactivated_at !== null => null,
            $this->isStudent($user) => self::STUDENT,
            $this->assignments->isTeacher($user) => self::FROM_TEACHERS,
            default => self::NOT_FROM_PANEL,
        };
    }

    /**
     * Si quien da de baja sigue activo, releído de la base: dos admin que se dan de baja uno al otro a la vez no se
     * quitan los dos (CF-05). Quien escribe lo pregunta bajo RoleAssignmentRules::ADMIN_SEATS_LOCK.
     */
    public function actorStillActive(User $actor): bool
    {
        return (bool) User::query()->whereKey($actor->getKey())->value('is_active');
    }

    /**
     * CF-08: por qué el admin no le manda el enlace de AC-16; nulo si sí: PasswordResetRules::denial() del enlace del
     * admin, que no pide el correo confirmado (confirmado el 14 de noviembre de 2026). Que ya se le mandara uno hace
     * menos de un minuto (AC-17) se sabe al mandarlo.
     */
    public function resetBlocker(User $user): ?string
    {
        return $this->passwordReset->denial($user, issuedByAdmin: true);
    }

    /**
     * CF-07: por qué el admin no le manda la invitación de acceso a `$user`; nulo si sí. La cuenta tiene que estar activa
     * (una dada de baja se reactiva antes, CF-06), sin contraseña y no ser de un alumno (CF-S4: su acceso sigue su
     * estatus), y el correo al que va (`$email`, o el suyo) tiene que ser del dominio institucional. Que tenga un puesto
     * no lo impide: la invitación del admin es para todo el personal.
     */
    public function invitationBlocker(User $user, ?string $email = null): ?string
    {
        return match (true) {
            ! $user->is_active => self::INVITE_INACTIVE,
            $this->isStudent($user) => self::STUDENT,
            $user->password !== null => self::INVITE_HAS_ACCESS,
            ! $this->passwordReset->isInstitutionalEmail($email ?? $user->email) => self::INVITE_NO_EMAIL,
            default => null,
        };
    }
}
