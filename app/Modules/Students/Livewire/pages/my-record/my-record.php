<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Queries\MyRecordQuery;
use App\Modules\Students\Queries\StudentCredentialQuery;
use App\Modules\Students\Queries\StudentRecordQuery;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * «Mis datos» del alumno (AC-15, supuesto por confirmar): mientras no haya pantallas de alumno (R3), su portada mínima
 * con sus datos escolares (número de control, carrera, plan, campus, semestre y estatus) y su acceso: su usuario, su
 * correo institucional y si sigue con el NIP del SII, con el enlace para cambiar su contraseña, que no es obligatorio
 * (AC-12). Tiene cuatro pestañas, con `?tab=`: «Mis datos» (por omisión), «Retícula» y «Kárdex» (solo lectura, con las
 * cifras arriba de ambas; tarea 1.42) y «Credencial» (su credencial de alumno). `?tab=perfil`, de los enlaces viejos, abre «Retícula». Solo lectura y solo de lo suyo: el permiso personal `students.my_record.view`, que solo tiene el alumno.
 */
new #[Title('Mis datos')] class extends Component
{
    /** 'datos', 'reticula', 'kardex' o 'credencial'; 'perfil' (enlaces viejos) abre la retícula; va en la URL y otro valor muestra «Mis datos». */
    #[Url]
    public mixed $tab = 'datos';

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
     * @return array<string, mixed>
     */
    public function with(Actor $context, MyRecordQuery $records, StudentRecordQuery $profiles, StudentCredentialQuery $credentials): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        $student = $records->for($user);

        return [
            'student' => $student,
            'record' => $student !== null && in_array($this->tab, ['reticula', 'kardex'], true) ? $profiles->forStudent($student) : null,
            'credential' => $student !== null && $this->tab === 'credencial' ? $credentials->of($context, $student) : null,
            'username' => $user->username,
            'email' => $user->email,
            'usesSiiNip' => $user->password_origin === PasswordOrigin::SiiNip,
        ];
    }
};
