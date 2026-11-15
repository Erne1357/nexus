{{--
    El menú «Acciones» de la cuenta en el encabezado de la ficha (1.22-C1; DESIGN.md, «Acciones de fila»): con la cuenta
    activa, «Editar datos», «Restablecer contraseña» y «Dar de baja»; dada de baja, «Editar datos» y «Reactivar». Lo que
    no se puede queda deshabilitado con su motivo en un segundo renglón (UserAccountQuery) y sin los atributos de acción.
    Restablecer y Reactivar se confirman con `x-confirm`; Editar abre un `x-modal` y Dar de baja un `x-drawer`
    (ADR-0023). Espera $account, $abilities y $resetMinutes.
--}}
@php($person = $account->person)

<x-core::row-menu :name="$person->name" data-testid="account-menu">
    @if ($abilities['update'])
        @if ($account->updateBlocker === null)
            <x-core::row-menu-item label="Editar datos" wire:click="openProfile" spinner="openProfile" data-testid="account-edit" />
        @else
            <x-core::row-menu-item label="Editar datos" :reason="$account->updateBlocker" disabled />
        @endif
    @endif

    @if ($person->is_active)
        @if ($abilities['resetPassword'])
            @if ($account->resetBlocker === null)
                <x-core::row-menu-item
                    label="Restablecer contraseña"
                    wire:click="sendPasswordReset"
                    spinner="sendPasswordReset"
                    x-confirm="Le llega a {{ $person->email }} un enlace para elegir una contraseña nueva. Vale {{ $resetMinutes }} minutos."
                    data-confirm-title="¿Mandar el enlace a {{ $person->username }}?"
                    data-confirm-button="Mandar enlace"
                    data-confirm-tone="primary"
                    data-testid="account-reset"
                />
            @else
                <x-core::row-menu-item label="Restablecer contraseña" :reason="$account->resetBlocker" disabled />
            @endif
        @endif

        @if ($abilities['deactivate'])
            @if ($account->deactivateBlocker === null)
                <x-core::row-menu-item label="Dar de baja" danger wire:click="openDeactivate" spinner="openDeactivate" data-testid="account-deactivate" />
            @else
                <x-core::row-menu-item label="Dar de baja" danger :reason="$account->deactivateBlocker" disabled />
            @endif
        @endif
    @elseif ($abilities['reactivate'])
        @if ($account->reactivateBlocker === null)
            <x-core::row-menu-item
                label="Reactivar"
                wire:click="reactivate"
                spinner="reactivate"
                x-confirm="La cuenta vuelve activa, sin acceso y sin puestos: nómbralo otra vez si hace falta."
                data-confirm-title="¿Reactivar la cuenta de {{ $person->username }}?"
                data-confirm-button="Reactivar"
                data-confirm-tone="warning"
                data-testid="account-reactivate"
            />
        @else
            <x-core::row-menu-item label="Reactivar" :reason="$account->reactivateBlocker" disabled />
        @endif
    @endif
</x-core::row-menu>
