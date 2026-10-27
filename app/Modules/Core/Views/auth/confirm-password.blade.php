<x-core::layouts.guest title="Confirmar contraseña">
    <x-card title="Confirma tu contraseña" subtitle="Por seguridad, escríbela de nuevo para continuar" shadow separator class="w-full max-w-sm">
        <form method="POST" action="{{ route('password.confirm.store') }}" class="flex flex-col gap-4">
            @csrf

            <x-password
                label="Contraseña"
                name="password"
                error-field="password"
                autocomplete="current-password"
                autofocus
                right
                required
            />

            <x-button label="Confirmar" type="submit" class="btn-primary" />
        </form>
    </x-card>
</x-core::layouts.guest>
