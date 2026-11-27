<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Students\Actions\CancelCredentialRequest;
use App\Modules\Students\Actions\RequestCredential;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Models\CredentialRequest;
use App\Modules\Students\Queries\CredentialRequestQuery;
use App\Modules\Students\Queries\MyRecordQuery;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use App\Modules\Students\Rules\CredentialRequestRules;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Mis datos» del alumno (AC-15, supuesto por confirmar): mientras no haya pantallas de alumno (R3), su portada mínima
 * con sus datos escolares (número de control, carrera, plan, campus, semestre y estatus) y su acceso: su usuario, su
 * correo institucional y si sigue con el NIP del SII, con el enlace para cambiar su contraseña (cambiar el NIP es obligatorio,
 * AC-12). Tiene cuatro pestañas, con `?tab=`: «Mis datos» (por omisión), «Retícula» y «Kárdex» (solo lectura, con las
 * cifras arriba de ambas; tarea 1.42) y «Credencial» (su credencial de alumno). `?tab=perfil`, de los enlaces viejos, abre «Retícula». Solo lectura y solo de lo suyo: el permiso personal `students.my_record.view`, que solo tiene el alumno.
 */
new #[Title('Mis datos')] class extends Component
{
    /** 'datos', 'reticula', 'kardex' o 'credencial'; 'perfil' (enlaces viejos) abre la retícula; va en la URL y otro valor muestra «Mis datos». */
    #[Url]
    public mixed $tab = 'datos';

    /** El drawer «Solicitar» de la pestaña «Credencial» (CO-07) y sus dos casillas. */
    public bool $showRequest = false;

    public bool $wantsReposition = false;

    public bool $wantsPhoto = false;

    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StudentsPermission::MyRecordView), 403);
        $this->updatedTab();
    }

    public function updatedTab(): void
    {
        $this->tab = match (true) {
            ! is_string($this->tab) => 'datos',
            $this->tab === 'perfil' => 'reticula',
            in_array($this->tab, ['reticula', 'kardex', 'credencial'], true) => $this->tab,
            default => 'datos',
        };
    }

    /**
     * CO-07: pide lo que marcó en el drawer. Las reglas (credencial vigente, una abierta por tipo, plazos) las valida la Action.
     */
    public function requestCredential(Actor $context, RequestCredential $request, MyRecordQuery $records): void
    {
        $user = $context->user();
        $student = $user !== null ? $records->for($user) : null;
        abort_if($student === null, 403);
        Gate::authorize('create', [CredentialRequest::class, $student]);

        $request->handle($student, $this->wantsReposition, $this->wantsPhoto, $user);

        $this->reset('wantsReposition', 'wantsPhoto', 'showRequest');
    }

    /**
     * CO-S2: cancela una solicitud suya abierta mientras está solicitada. El id se busca solo entre las abiertas del propio
     * alumno; otro id o una que no está abierta da 404, y la Policy exige que siga solicitada.
     */
    public function cancelRequest(string $id, Actor $context, CancelCredentialRequest $cancel, MyRecordQuery $records, CredentialRequestQuery $requests): void
    {
        $user = $context->user();
        $student = $user !== null ? $records->for($user) : null;
        abort_if($student === null, 403);

        $open = $requests->history($student)->first(fn (CredentialRequest $request): bool => $request->status->isOpen() && $request->getKey() === $id);
        abort_if($open === null, 404);
        Gate::authorize('cancel', $open);

        $cancel->handle($open, $user);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, MyRecordQuery $records, StudentRecordQuery $profiles, StudentCredentialQuery $credentials, CredentialRequestQuery $requests): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        $student = $records->for($user);

        $onCredentialTab = $student !== null && $this->tab === 'credencial';
        $history = $onCredentialTab ? $requests->history($student) : null;

        return [
            'student' => $student,
            'requests' => $history,
            'openRequests' => $history?->filter(fn (CredentialRequest $request): bool => $request->status->isOpen())->values(),
            'availability' => $onCredentialTab ? CredentialRequestRules::availability($student, CarbonImmutable::now()) : null,
            'record' => $student !== null && in_array($this->tab, ['reticula', 'kardex'], true) ? $profiles->forStudent($student) : null,
            'credential' => $student !== null && $this->tab === 'credencial' ? $credentials->of($context, $student) : null,
            'username' => $user->username,
            'email' => $user->email,
            'usesSiiNip' => $user->password_origin === PasswordOrigin::SiiNip,
        ];
    }
};
