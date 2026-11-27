{{-- El formulario de cambio de contraseña dentro de la credencial (core::auth.change-password): manda a Fortify
(`PUT /user/password`, UpdateUserPassword). Cada tecla revisa los requisitos (`nexusPassword`). --}}
<form method="POST" action="{{ route('user-password.update') }}" class="flex flex-col gap-3.5" x-on:input="check($el)">
    @csrf
    @method('PUT')

    <x-password label="Contraseña actual" name="current_password" error-field="current_password" autocomplete="current-password" right required />
    <x-password label="Nueva contraseña" name="password" error-field="password" autocomplete="new-password" right required />
    <x-password label="Confirma la nueva contraseña" name="password_confirmation" error-field="password_confirmation" autocomplete="new-password" right required />

    <x-core::password.requirements :requirements="$requirements" />

    <x-button label="Guardar contraseña" type="submit" class="btn-primary mt-1 h-12 text-base" x-bind:disabled="! ready" />
</form>

{{-- Abajo, salir (primer acceso) o volver (cambio voluntario) y el aviso de privacidad del TecNM (AC-23), que se abre en otra pestaña para no perder lo escrito --}}
@php($privacyLink = 'inline-flex items-center gap-1.5 py-1 text-[14.5px] text-neutral underline decoration-base-300 underline-offset-[3px] hover:text-base-content hover:decoration-current')

<div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-1">
    @if ($firstAccess)
        <a href="{{ route('privacy-notice') }}" target="_blank" rel="noopener" data-testid="password-privacy-notice" class="{{ $privacyLink }}">
            Aviso de privacidad<span class="sr-only"> (se abre en otra pestaña)</span>
        </a>
        <form method="POST" action="{{ route('logout') }}">
            @csrf
            <button type="submit" class="py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">Cerrar sesión</button>
        </form>
    @else
        <a href="{{ $backTo }}" class="inline-flex items-center gap-1.5 py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">
            <x-icon name="o-arrow-left" class="h-4 w-4" />Volver sin cambiarla
        </a>
        <a href="{{ route('privacy-notice') }}" target="_blank" rel="noopener" data-testid="password-privacy-notice" class="{{ $privacyLink }}">
            Aviso de privacidad<span class="sr-only"> (se abre en otra pestaña)</span>
        </a>
    @endif
</div>
