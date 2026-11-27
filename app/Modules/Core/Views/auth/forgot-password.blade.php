{{--
    «¿Olvidaste tu contraseña?» (AC-16; DESIGN.md, "Pantallas de contraseña"): la credencial oficial en la escena del
    login, sin ficha, pide el usuario (nunca el correo). Después de mandar, cuelga el talón «Solicitud recibida» y dice
    siempre lo mismo, exista o no el usuario y pueda o no restablecer por correo. `$minutes` es cuánto vale el enlace.
--}}
<x-core::layouts.guest title="Restablecer contraseña" bare>
    <x-core::login.scene>
        <x-core::password.badge printed heading="¿Olvidaste tu contraseña?" lead="Te mandamos un enlace a tu correo institucional para elegir otra.">
            <form method="POST" action="{{ route('password.email') }}" class="flex flex-col gap-3.5">
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

                <x-button label="Mandar enlace" type="submit" icon="o-envelope" class="btn-primary mt-1 h-12 text-base" />
            </form>

            <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 self-start py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">
                <x-icon name="o-arrow-left" class="h-4 w-4" />Volver a iniciar sesión
            </a>

            @if (session('status') === 'password-reset-link-requested')
                <x-slot:receipt>
                    <x-core::password.receipt title="Solicitud recibida">
                        <p class="mt-2 text-[14.5px] text-pretty" role="status">Si tu usuario puede restablecer su contraseña por correo, en unos minutos te llegará un enlace a tu correo institucional.</p>
                        <p class="mt-1 text-[13.5px] text-pretty text-neutral">Vence en {{ $minutes }} minutos. Si no llega, revisa el correo no deseado o pídele al administrador del sistema que restablezca tu contraseña.</p>
                    </x-core::password.receipt>
                </x-slot:receipt>
            @endif
        </x-core::password.badge>
    </x-core::login.scene>
</x-core::layouts.guest>
