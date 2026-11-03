{{--
    El estado de una persona en la lista de «Personas y puestos» (core::pages.people): «Usuario inactivo» (la cuenta
    está desactivada), «Con puesto» (tiene algún nombramiento vigente) o «Sin puesto vigente».
--}}
@if (! $user->is_active)
    <x-core::status-badge tone="neutral" label="Usuario inactivo" />
@elseif ($user->roleAssignments->isNotEmpty())
    <x-core::status-badge tone="success" label="Con puesto" />
@else
    <x-core::status-badge tone="neutral" label="Sin puesto vigente" />
@endif
