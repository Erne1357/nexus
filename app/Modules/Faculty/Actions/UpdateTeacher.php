<?php

namespace App\Modules\Faculty\Actions;

use App\Modules\Core\Actions\CancelAccessInvitation;
use App\Modules\Core\Actions\SendAccessInvitation;
use App\Modules\Core\Models\User;
use App\Modules\Faculty\Enums\TeacherDegree;
use App\Modules\Faculty\Events\TeacherUpdated;
use App\Modules\Faculty\Models\Teacher;
use App\Modules\Faculty\Rules\TeacherRules;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Spatie\Activitylog\Enums\ActivityEvent;

/**
 * Cambia los datos de un docente: nombre, correo, correo institucional (AC-06), grado y fecha de ingreso, solo las
 * claves que recibe. Quién puede lo decide TeacherPolicy (`update`) antes de llamarla. Hace cumplir las mismas reglas
 * que CreateTeacher (TeacherRules). Si su usuario ya tenía una invitación de acceso y sigue sin contraseña, cambiar el
 * correo institucional la manda de nuevo al correo nuevo, así que el enlace anterior deja de servir; borrarlo, o
 * cambiarlo a un docente inactivo, la deja sin efecto (AC-07, supuesto por confirmar). Va en la misma transacción.
 *
 * No cambia el RFC ni el estado: el RFC es el usuario del docente y la clave del SII (supuesto
 * por confirmar: no se edita), y la baja es SetTeacherActive, que además sincroniza su acceso.
 *
 * En una transacción vuelve a leer al docente con bloqueo (`lockForUpdate`), así el valor anterior de la bitácora
 * es el confirmado aunque `$teacher` llegue viejo, y lo deja en `$teacher`. Si nada cambió, no escribe, no registra
 * ni avisa; si cambió, deja en la bitácora el valor anterior y el nuevo y, después del commit, avisa con
 * TeacherUpdated.
 */
final class UpdateTeacher
{
    /** Lo que se edita y se registra en la bitácora. */
    public const array FIELDS = ['name', 'email', 'institutional_email', 'degree', 'hired_on'];

    public function __construct(
        private readonly TeacherRules $rules,
        private readonly SendAccessInvitation $sendAccessInvitation,
        private readonly CancelAccessInvitation $cancelAccessInvitation,
    ) {}

    /**
     * @param  array{name?: string, email?: ?string, institutional_email?: ?string, degree?: TeacherDegree|string|null, hired_on?: ?string}  $data
     *
     * @throws ValidationException
     * @throws RuntimeException si hay que volver a mandar la invitación y falta NEXUS_DEFAULT_PASSWORD
     */
    public function handle(Teacher $teacher, array $data): Teacher
    {
        $data = Arr::only($data, self::FIELDS);

        if (array_key_exists('name', $data)) {
            $data['name'] = $this->rules->name($data['name']);
        }

        if (array_key_exists('degree', $data)) {
            $data['degree'] = $this->rules->degree($data['degree']);
        }

        if (array_key_exists('institutional_email', $data)) {
            $data['institutional_email'] = $this->rules->institutionalEmail($data['institutional_email']);
        }

        foreach (['email', 'hired_on'] as $field) {
            if (array_key_exists($field, $data)) {
                $data[$field] = $this->rules->optional($data[$field]);
            }
        }

        return DB::transaction(function () use ($teacher, $data): Teacher {
            $teacher->setRawAttributes($teacher->newQuery()->lockForUpdate()->findOrFail($teacher->getKey())->getAttributes(), sync: true);
            $old = $this->rules->logged($teacher, self::FIELDS);

            if (array_key_exists('institutional_email', $data) && $data['institutional_email'] === mb_strtolower((string) $teacher->institutional_email)) {
                unset($data['institutional_email']);
            }

            $teacher->fill($data);
            $changed = array_keys($teacher->getDirty());

            if ($changed === []) {
                return $teacher;
            }

            if (in_array('institutional_email', $changed, true) && $teacher->institutional_email !== null) {
                $this->rules->ensureInstitutionalEmailIsFree($teacher->institutional_email, $teacher);
            }

            try {
                $teacher->save();
            } catch (UniqueConstraintViolationException) {
                throw $this->rules->institutionalEmailTaken((string) $teacher->institutional_email);
            }

            activity()
                ->performedOn($teacher)
                ->event(ActivityEvent::Updated)
                ->withChanges([
                    'attributes' => $this->rules->logged($teacher, $changed),
                    'old' => Arr::only($old, $changed),
                ])
                ->log("Actualizó al docente {$teacher->rfc}");

            $actor = auth()->user();

            if (in_array('institutional_email', $changed, true)) {
                $this->refreshInvitation($teacher, $actor instanceof User ? $actor : null);
            }

            TeacherUpdated::dispatch($teacher, $changed, $actor);

            return $teacher;
        });
    }

    /**
     * La invitación de acceso del usuario del docente tras cambiar su correo institucional: de nuevo al correo nuevo, o
     * sin efecto si ya no tiene correo o está inactivo. Sin usuario, con contraseña o sin invitación, nada. Un correo
     * que ya es de otro usuario sale como error del correo institucional.
     *
     * @throws ValidationException
     */
    private function refreshInvitation(Teacher $teacher, ?User $actor): void
    {
        $user = $teacher->user_id === null ? null : $teacher->user()->first();

        if ($user === null || $user->password !== null || $user->access_invited_at === null) {
            return;
        }

        if ($teacher->institutional_email === null || ! $teacher->is_active) {
            $this->cancelAccessInvitation->handle($user, $actor);

            return;
        }

        try {
            $this->sendAccessInvitation->handle($user, $teacher->institutional_email, $actor);
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(['institutional_email' => (string) collect($exception->errors())->flatten()->first()]);
        }
    }
}
