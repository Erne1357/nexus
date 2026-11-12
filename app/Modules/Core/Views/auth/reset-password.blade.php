{{--
    Elegir la contraseña nueva con el enlace del correo (AC-17 y AC-18). Con `$username`, el enlace sirve: muestra el
    usuario y el formulario, que manda escondidos el token y el usuario. Sin él, el enlace no sirve y la página lo dice
    sin decir por qué.
--}}
<x-core::layouts.guest title="Restablecer contraseña">
    @if ($username)
        <x-card title="Elige una contraseña nueva" subtitle="Al menos 8 caracteres, con letras y números" shadow separator class="w-full max-w-sm">
            <p class="mb-4">Tu usuario es <span class="font-mono font-semibold">{{ $username }}</span>.</p>

            <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-4">
                @csrf
                <input type="hidden" name="token" value="{{ $token }}">
                <input type="hidden" name="username" value="{{ $username }}" autocomplete="username">

                <x-password
                    label="Nueva contraseña"
                    name="password"
                    error-field="password"
                    autocomplete="new-password"
                    right
                    autofocus
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
        </x-card>
    @else
        <x-card title="Este enlace ya no sirve" shadow separator class="w-full max-w-sm">
            <p class="mb-4">Puede que haya vencido, que ya se haya usado o que hayas pedido uno más nuevo.</p>

            <div class="flex flex-col gap-2">
                <x-button label="Pedir otro enlace" :link="route('password.request')" class="btn-primary w-full" />
                <x-button label="Ir a iniciar sesión" :link="route('login')" class="btn-outline w-full" />
            </div>
        </x-card>
    @endif
</x-core::layouts.guest>
