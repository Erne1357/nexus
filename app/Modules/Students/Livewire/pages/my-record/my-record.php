<?php

use App\Modules\Core\Authorization\Actor;
use App\Modules\Core\Authorization\Target;
use App\Modules\Core\Enums\PasswordOrigin;
use App\Modules\Students\Enums\StudentsPermission;
use App\Modules\Students\Queries\MyRecordQuery;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * «Mis datos» del alumno (AC-15, supuesto por confirmar): mientras no haya pantallas de alumno (R3), su portada mínima
 * con sus datos escolares (número de control, carrera, plan, campus, semestre y estatus) y su acceso: su usuario, su
 * correo institucional y si sigue con el NIP del SII, con el enlace para cambiar su contraseña, que no es obligatorio
 * (AC-12). Solo lectura y solo de lo suyo: el permiso personal `students.my_record.view`, que solo tiene el alumno.
 */
new #[Title('Mis datos')] class extends Component
{
    public function mount(Actor $context): void
    {
        abort_unless($context->allowsAny(StudentsPermission::MyRecordView), 403);
    }

    /**
     * @return array<string, mixed>
     */
    public function with(Actor $context, MyRecordQuery $records): array
    {
        $user = $context->user();
        abort_unless($user !== null && $context->allows(StudentsPermission::MyRecordView, Target::ownedBy((string) $user->getKey())), 403);

        return [
            'student' => $records->for($user),
            'username' => $user->username,
            'email' => $user->email,
            'usesSiiNip' => $user->password_origin === PasswordOrigin::SiiNip,
        ];
    }
};
