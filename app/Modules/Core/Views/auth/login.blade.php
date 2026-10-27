<x-core::layouts.guest title="Iniciar sesión">
    <x-card title="Nexus" subtitle="Entra con tu usuario del SII" shadow separator class="w-full max-w-sm">
        <form method="POST" action="{{ route('login.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-input
                label="Usuario"
                name="username"
                :value="old('username')"
                error-field="username"
                hint="Número de empleado o número de control"
                autocomplete="username"
                autofocus
                required
            />

            <x-password
                label="Contraseña"
                name="password"
                error-field="password"
                autocomplete="current-password"
                right
                required
            />

            <x-checkbox label="Recordarme" name="remember" value="1" :checked="(bool) old('remember')" />

            <x-button label="Entrar" type="submit" class="btn-primary" />
        </form>
    </x-card>
</x-core::layouts.guest>
