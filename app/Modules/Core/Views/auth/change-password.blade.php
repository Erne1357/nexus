<x-core::layouts.guest title="Cambiar contraseña">
    <x-card title="Cambia tu contraseña" subtitle="Al menos 8 caracteres, con letras y números" shadow separator class="w-full max-w-sm">
        @if (session('status') === 'password-updated')
            <x-alert title="Tu contraseña se actualizó." icon="o-check-circle" class="alert-success mb-4" />
        @endif

        @if (auth()->user()->must_change_password)
            <x-alert
                title="Es tu primer acceso: elige una contraseña propia para continuar."
                icon="o-key"
                class="alert-info mb-4"
            />
        @endif

        <form method="POST" action="{{ route('user-password.update') }}" class="flex flex-col gap-4">
            @csrf
            @method('PUT')

            <x-password
                label="Contraseña actual"
                name="current_password"
                error-field="current_password"
                autocomplete="current-password"
                right
                required
            />

            <x-password
                label="Nueva contraseña"
                name="password"
                error-field="password"
                autocomplete="new-password"
                right
                required
            />

            <x-password
                label="Confirma la nueva contraseña"
                name="password_confirmation"
                error-field="password_confirmation"
                autocomplete="new-password"
                right
                required
            />

            <x-button label="Guardar contraseña" type="submit" class="btn-primary" />
        </form>

        <x-slot:actions>
            <form method="POST" action="{{ route('logout') }}">
                @csrf

                <x-button label="Cerrar sesión" type="submit" class="btn-ghost btn-sm" />
            </form>
        </x-slot:actions>
    </x-card>
</x-core::layouts.guest>
