{{--
    Correo institucional del docente (AC-06), el mismo en «Editar docente» de la lista (faculty::pages.teachers) y en
    «Editar datos» de la ficha (faculty::pages.teacher), para que no vuelvan a divergir: con la pista del dominio
    (`$domain`) o, si no le toca cambiarlo, deshabilitado con su motivo (`$reason`, de EditsInstitutionalEmail).
--}}
@if ($reason === null)
    <x-input label="Correo institucional" name="institutional_email" wire:model="institutional_email" type="email" :hint="'@'.$domain.'. A él va su invitación de acceso.'" />
@else
    <x-input label="Correo institucional" name="institutional_email" wire:model="institutional_email" type="email" disabled :hint="$reason" />
@endif
