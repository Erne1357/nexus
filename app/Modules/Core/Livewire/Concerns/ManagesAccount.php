<?php

namespace App\Modules\Core\Livewire\Concerns;

use App\Modules\Core\Actions\DeactivateUser;
use App\Modules\Core\Actions\InviteUser;
use App\Modules\Core\Actions\ReactivateUser;
use App\Modules\Core\Actions\SendUserPasswordReset;
use App\Modules\Core\Actions\UpdateUserProfile;
use App\Modules\Core\Enums\DeactivationReason;
use App\Modules\Core\Models\RoleAssignment;
use App\Modules\Core\Models\User;
use App\Modules\Core\Queries\PeopleQuery;
use App\Modules\Core\Support\PasswordResetTokens;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Throwable;

/**
 * Las acciones del menú «Acciones» de la cuenta en la ficha de la persona (1.22-C1 y C2): «Editar datos»
 * (UpdateUserProfile, en un `x-modal`), «Enviar invitación» o «Reenviar invitación» (InviteUser, CF-07, con
 * `x-confirm`), «Restablecer contraseña» (SendUserPasswordReset, CF-08, con `x-confirm`), «Dar de baja»
 * (DeactivateUser, CF-05 y CF-09, en un `x-drawer` con la vista previa) y «Reactivar» (ReactivateUser, CF-06, que
 * también invita, con `x-confirm`), según ADR-0023. Cada método vuelve a autorizar la ficha (RoleAssignmentPolicy) y
 * su ability de UserPolicy, porque una petición puede llegar con una página que abrió otra sesión. Los rechazos de las
 * reglas salen junto al campo o en un toast. El componente da `personId` y `use Toast`.
 */
trait ManagesAccount
{
    public bool $showProfile = false;

    public string $profileName = '';

    public string $profileEmail = '';

    public bool $showDeactivate = false;

    public string $deactivationReason = '';

    public string $deactivationNote = '';

    public function openProfile(PeopleQuery $people): void
    {
        $person = $this->accountPerson($people, 'update');

        $this->resetValidation();
        $this->profileName = $person->name;
        $this->profileEmail = (string) $person->email;
        $this->showProfile = true;
    }

    public function saveProfile(PeopleQuery $people, UpdateUserProfile $updateUserProfile): void
    {
        $person = $this->accountPerson($people, 'update');

        $this->validate([
            'profileName' => ['required', 'string', 'max:160'],
            'profileEmail' => ['nullable', 'string', 'max:255'],
        ], [
            'profileName.required' => 'Escribe el nombre.',
            'profileName.max' => 'El nombre no puede pasar de 160 caracteres.',
            'profileEmail.max' => 'El correo no puede pasar de 255 caracteres.',
        ]);

        try {
            $updated = $updateUserProfile->handle($person, ['name' => $this->profileName, 'email' => $this->profileEmail], auth()->user());
        } catch (ValidationException $exception) {
            throw ValidationException::withMessages(collect($exception->errors())->mapWithKeys(fn (array $messages, string $key): array => [
                $key === 'email' ? 'profileEmail' : 'profileName' => $messages,
            ])->all());
        }

        $this->showProfile = false;
        $this->success('Datos guardados', e($updated->name.($updated->email === null ? ' · sin correo institucional' : ' · '.$updated->email)));
    }

    /**
     * CF-08: el enlace de AC-16 a su correo institucional. El resultado, o por qué no se pudo, en un toast.
     */
    public function sendPasswordReset(PeopleQuery $people, SendUserPasswordReset $sendUserPasswordReset, PasswordResetTokens $tokens): void
    {
        $person = $this->accountPerson($people, 'resetPassword');

        try {
            $email = $sendUserPasswordReset->handle($person, auth()->user());
        } catch (ValidationException $exception) {
            $this->error('No se mandó el enlace', e((string) collect($exception->errors())->flatten()->first()));

            return;
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No se mandó el enlace', 'El correo no salió. Inténtalo otra vez en unos minutos.');

            return;
        }

        $this->success('Enlace enviado', e("Enlace enviado a {$email}. Vale {$tokens->minutes()} minutos."));
    }

    /**
     * CF-07: la invitación a su correo institucional, con usuario y enlace; una nueva deja sin efecto la anterior. El
     * resultado, o por qué no se pudo, en un toast.
     */
    public function invite(PeopleQuery $people, InviteUser $inviteUser): void
    {
        $person = $this->accountPerson($people, 'invite');
        $resent = $person->access_invited_at !== null;

        try {
            $email = $inviteUser->handle($person, auth()->user());
        } catch (ValidationException $exception) {
            $this->error($resent ? 'No se reenvió la invitación' : 'No se mandó la invitación', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->success($resent ? 'Invitación reenviada' : 'Invitación enviada', e("Invitación enviada a {$email}.".($resent ? ' El enlace anterior ya no sirve.' : '')));
    }

    public function openDeactivate(PeopleQuery $people): void
    {
        $this->accountPerson($people, 'deactivate');

        $this->resetValidation();
        $this->reset('deactivationReason', 'deactivationNote');
        $this->showDeactivate = true;
    }

    /**
     * CF-05: termina hoy sus puestos y deja la cuenta sin acceso. El rechazo de las reglas (uno mismo, un alumno, el
     * último admin) sale en el cajón.
     */
    public function deactivate(PeopleQuery $people, DeactivateUser $deactivateUser): void
    {
        $person = $this->accountPerson($people, 'deactivate');

        $this->validate([
            'deactivationReason' => ['required', Rule::enum(DeactivationReason::class)],
            'deactivationNote' => ['nullable', 'string', 'max:500'],
        ], [
            'deactivationReason.required' => 'Elige el motivo.',
            'deactivationReason.enum' => 'Elige un motivo de la lista.',
            'deactivationNote.max' => 'La nota no puede pasar de 500 caracteres.',
        ]);

        $result = $deactivateUser->handle($person, DeactivationReason::from($this->deactivationReason), $this->deactivationNote, auth()->user());
        $outcome = $deactivateUser->outcome($result);

        $this->showDeactivate = false;
        $this->success('Cuenta dada de baja', e($outcome === null ? 'No tenía puestos que terminar.' : ucfirst($outcome).'.'));
    }

    /**
     * CF-06: la cuenta vuelve activa y sin puestos, y con correo institucional le llega la invitación (CF-07). Un
     * rechazo, en un toast.
     */
    public function reactivate(PeopleQuery $people, ReactivateUser $reactivateUser): void
    {
        $person = $this->accountPerson($people, 'reactivate');

        try {
            $email = $reactivateUser->handle($person, auth()->user());
        } catch (ValidationException $exception) {
            $this->error('No se reactivó', e((string) collect($exception->errors())->flatten()->first()));

            return;
        }

        $this->success('Cuenta reactivada', e(($email === null
            ? 'Queda sin acceso: captura su correo institucional y mándale la invitación.'
            : "Invitación enviada a {$email}.").' Sus puestos no regresan: nómbralo otra vez si hace falta.'));
    }

    /**
     * Lo que el menú y sus diálogos necesitan saber de quien mira: qué abilities de UserPolicy tiene sobre la persona.
     *
     * @return array{update: bool, invite: bool, resetPassword: bool, deactivate: bool, reactivate: bool}
     */
    protected function accountAbilities(User $person): array
    {
        return [
            'update' => Gate::allows('update', $person),
            'invite' => Gate::allows('invite', $person),
            'resetPassword' => Gate::allows('resetPassword', $person),
            'deactivate' => Gate::allows('deactivate', $person),
            'reactivate' => Gate::allows('reactivate', $person),
        ];
    }

    /**
     * La persona de la ficha, con la ficha y la ability `$ability` de UserPolicy autorizadas.
     */
    private function accountPerson(PeopleQuery $people, string $ability): User
    {
        $this->authorize('viewAny', RoleAssignment::class);
        $person = $people->findOrFail($this->personId);
        $this->authorize($ability, $person);

        return $person;
    }
}
