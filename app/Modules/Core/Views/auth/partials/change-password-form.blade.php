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

@if ($firstAccess)
    <form method="POST" action="{{ route('logout') }}" class="flex justify-end">
        @csrf
        <button type="submit" class="py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">Cerrar sesión</button>
    </form>
@else
    <a href="{{ $backTo }}" class="inline-flex items-center gap-1.5 self-start py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">
        <x-icon name="o-arrow-left" class="h-4 w-4" />Volver sin cambiarla
    </a>
@endif
