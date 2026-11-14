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
 *   (RoleAssignmentRules::isStudentAccount()); solo se le restablece la contraseña.
 * - CF-05: nadie se da de baja a sí mismo (supuesto por confirmar) y no se da de baja a quien dejaría algún día sin admin
 *   vigente (AZ-S12, RoleAssignmentRules::deactivationGap()).
 * - CF-08: el restablecimiento sigue AC-16 (PasswordResetRules); `resetBlocker()` dice por qué no, en el mismo orden.
 */
final class UserAccountRules
{
    public const string STUDENT = 'Es alumno: su cuenta se activa y se desactiva con su estatus en el SII.';

    public const string SELF = 'No puedes darte de baja a ti mismo.';

    public const string ALREADY_INACTIVE = 'La cuenta ya está dada de baja.';

    public const string ALREADY_ACTIVE = 'La cuenta ya está activa.';

    public const string RESET_INACTIVE = PasswordResetRules::INACTIVE;

    public const string RESET_NO_PASSWORD = PasswordResetRules::NO_PASSWORD;

    public const string RESET_NO_EMAIL = PasswordResetRules::NO_EMAIL;

    public const string RESET_UNVERIFIED = PasswordResetRules::UNVERIFIED;

    public const string RESET_RECENT = 'Ya se le mandó un enlace hace menos de un minuto.';

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
     * CF-S4: si la cuenta es de un alumno.
     */
    public function isStudent(User $user): bool
    {
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
     * Por qué no se reactiva (CF-06, CF-S4); nulo si sí.
     */
    public function reactivationBlocker(User $user): ?string
    {
        return match (true) {
            $this->isStudent($user) => self::STUDENT,
            $user->is_active => self::ALREADY_ACTIVE,
            default => null,
        };
    }

    /**
     * CF-08: por qué no se le manda el enlace de AC-16; nulo si sí: PasswordResetRules::denial(), la misma regla que
     * `allows()`. Que ya se le mandara uno hace menos de un minuto (AC-17) se sabe al mandarlo.
     */
    public function resetBlocker(User $user): ?string
    {
        return $this->passwordReset->denial($user);
    }
}
