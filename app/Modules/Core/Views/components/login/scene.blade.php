{{-- La escena del login sin los avisos (DESIGN.md, "Pantallas de contraseña"): en escritorio, la foto del edificio a la
izquierda y a la derecha el panel con los logos en placas y la credencial que cuelga de su cinta (el slot, dentro de
core::password.lanyard); en celular, solo el panel. La usan las pantallas de contraseña que se ven sin la aplicación
(primer acceso, olvido y enlace del correo). Siempre en claro, como el login (layout de invitado con `bare`). --}}
<div data-login-scene class="grid min-h-svh lg:grid-cols-[minmax(0,1fr)_clamp(460px,42vw,720px)]" style="--hare: url('{{ Vite::asset('resources/images/liebre-512.png') }}')">
    <main class="order-1 mx-auto flex w-full max-w-[560px] flex-col bg-base-100 px-4 pt-6 pb-10 lg:order-2 lg:min-h-svh lg:max-w-none lg:px-[clamp(28px,3.4vw,56px)] lg:pt-[clamp(20px,3vh,32px)] lg:pb-[clamp(28px,5vh,56px)]">
        <x-core::login.plates />

        <div class="flex flex-1 items-start justify-center pt-[34px] lg:items-center lg:pt-[clamp(28px,4.5vh,48px)]">
            <x-core::password.lanyard>
                {{ $slot }}
            </x-core::password.lanyard>
        </div>
    </main>

    <img
        src="{{ Vite::asset('resources/images/login/edificio-itcj.webp') }}"
        alt=""
        class="hidden lg:sticky lg:top-0 lg:order-1 lg:block lg:h-svh lg:w-full lg:object-cover"
    />
</div>
