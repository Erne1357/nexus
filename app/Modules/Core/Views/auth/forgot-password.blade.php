{{--
    «¿Olvidaste tu contraseña?» (AC-16). Pide el usuario, nunca el correo, y después de mandar dice siempre lo mismo,
    exista o no el usuario y pueda o no restablecer por correo. `$minutes` es cuánto vale el enlace.
--}}
<x-core::layouts.guest title="Restablecer contraseña">
    <x-card title="¿Olvidaste tu contraseña?" subtitle="Te mandamos un enlace a tu correo institucional para elegir otra" shadow separator class="w-full max-w-sm">
        @if (session('status') === 'password-reset-link-requested')
            <x-alert
                title="Si tu usuario puede restablecer su contraseña por correo, en unos minutos te llegará un enlace a tu correo institucional."
                description="Vence en {{ $minutes }} minutos. Si no llega, revisa el correo no deseado o pídele al administrador del sistema que restablezca tu contraseña."
                icon="o-envelope"
                class="alert-info mb-4"
            />
        @endif

        <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-4">
            @csrf

            <x-input
                label="Usuario"
                name="username"
                :value="old('username')"
                error-field="username"
                hint="Tu RFC o tu número de control"
                autocomplete="username"
                autocapitalize="none"
                spellcheck="false"
                autofocus
                required
            />

            <x-button label="Mandar enlace" type="submit" icon="o-envelope" class="btn-primary" />
        </form>

        <x-slot:actions>
            <x-button label="Volver a iniciar sesión" :link="route('login')" icon="o-arrow-left" class="btn-ghost btn-sm" />
        </x-slot:actions>
    </x-card>
</x-core::layouts.guest>
