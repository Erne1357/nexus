{{--
    Elegir la contraseña nueva con el enlace del correo (AC-17 y AC-18; DESIGN.md, "Pantallas de contraseña"). Con
    `$username`, el enlace sirve: la credencial provisional en la escena del login, con el usuario en la ficha, se
    imprime con cada requisito (sin «distinta de la actual»: aquí no se pide); el formulario manda escondidos el token y
    el usuario. Al guardar lleva al login con «Tu contraseña quedó lista». Sin `$username`, el enlace no sirve: la
    credencial se queda provisional, «Sin validez», y la página no dice por qué.
--}}
<x-core::layouts.guest title="Restablecer contraseña" bare>
    <x-core::login.scene>
        @if ($username)
            <x-core::password.badge
                :credential="['username' => $username]"
                :requirements="\App\Modules\Core\Support\PasswordRequirements::forReset()"
                heading="Elige una contraseña nueva"
                lead="Llegaste por el enlace de tu correo institucional."
            >
                <form method="POST" action="{{ route('password.update') }}" class="flex flex-col gap-3.5" x-on:input="check($el)">
                    @csrf
                    <input type="hidden" name="token" value="{{ $token }}">
                    <input type="hidden" name="username" value="{{ $username }}" autocomplete="username">

                    <x-password label="Nueva contraseña" name="password" error-field="password" autocomplete="new-password" right autofocus required />
                    <x-password label="Confirma la nueva contraseña" name="password_confirmation" error-field="password_confirmation" autocomplete="new-password" right required />

                    <x-core::password.requirements :requirements="\App\Modules\Core\Support\PasswordRequirements::forReset()" />

                    <x-button label="Guardar contraseña" type="submit" class="btn-primary mt-1 h-12 text-base" x-bind:disabled="! ready" />
                </form>

                <a href="{{ route('login') }}" class="inline-flex items-center gap-1.5 self-start py-1 text-[14.5px] underline decoration-base-300 underline-offset-[3px] hover:decoration-current">
                    <x-icon name="o-arrow-left" class="h-4 w-4" />Ir a iniciar sesión
                </a>
            </x-core::password.badge>
        @else
            <x-core::password.badge void heading="Este enlace ya no sirve" lead="Puede que haya vencido, que ya se haya usado o que hayas pedido uno más nuevo.">
                <div class="flex flex-col gap-2">
                    <x-button label="Pedir otro enlace" :link="route('password.request')" icon="o-envelope" class="btn-primary h-12 w-full text-base" />
                    <x-button label="Ir a iniciar sesión" :link="route('login')" class="btn-ghost w-full" />
                </div>
            </x-core::password.badge>
        @endif
    </x-core::login.scene>
</x-core::layouts.guest>
