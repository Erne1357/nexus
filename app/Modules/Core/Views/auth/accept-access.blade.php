{{--
    Activar el acceso con la invitación (AC-08), con la forma de la pantalla de acceso (DESIGN.md, "Pantalla de
    acceso"): en escritorio, la foto del edificio a la izquierda y a la derecha el panel con los logos, la marca Nexus y
    el formulario; en celular, solo el panel. Con `$action`, el enlace sirve: muestra el usuario y el formulario para
    elegir la contraseña (sin la actual: el enlace basta), que se manda al mismo enlace firmado. Sin él, el enlace no
    sirve y la página lo dice sin decir por qué.
--}}
<x-core::layouts.guest title="Activar tu acceso" bare>
    <div class="grid min-h-svh lg:grid-cols-[minmax(0,1fr)_clamp(460px,42vw,720px)]">
        <main
            class="mx-auto flex w-full max-w-[560px] flex-col bg-base-100 px-4 pt-6 pb-7 lg:order-2 lg:min-h-svh lg:max-w-none lg:px-[clamp(28px,3.4vw,56px)] lg:pt-[clamp(20px,3vh,32px)] lg:pb-[clamp(28px,5vh,56px)]"
        >
            <x-core::login.brand />

            <section aria-labelledby="accept-access-title" class="mt-5 lg:mt-[clamp(20px,3.6vh,36px)]">
                @if ($action)
                    <h2 id="accept-access-title" class="text-[clamp(21px,1.5vw,25px)] font-bold">Activa tu acceso</h2>
                    <p class="mt-2 text-base">Tu usuario es <span class="font-mono font-semibold">{{ $username }}</span>. Elige tu contraseña: con ella entras a Nexus desde hoy.</p>

                    <form method="POST" action="{{ $action }}" class="mt-5 flex flex-col gap-4">
                        @csrf
                        <input type="hidden" name="username" value="{{ $username }}" autocomplete="username">

                        <x-password
                            label="Contraseña"
                            name="password"
                            error-field="password"
                            hint="Al menos 8 caracteres, con letras y números"
                            autocomplete="new-password"
                            right
                            autofocus
                            required
                        />

                        <x-password
                            label="Confirma tu contraseña"
                            name="password_confirmation"
                            error-field="password_confirmation"
                            autocomplete="new-password"
                            right
                            required
                        />

                        <x-button label="Activar mi acceso" type="submit" class="btn-primary mt-5 h-[50px] text-[16.5px]" />
                    </form>
                @else
                    <h2 id="accept-access-title" class="text-[clamp(21px,1.5vw,25px)] font-bold">Este enlace ya no sirve</h2>
                    <p class="mt-2 text-base">Puede que haya vencido, que ya se haya usado o que te hayan mandado uno más nuevo. Pide a tu jefe de departamento que te reenvíe la invitación.</p>

                    <x-button label="Ir a iniciar sesión" :link="route('login')" class="btn-outline mt-7 h-[50px] w-full text-[16.5px]" />
                @endif
            </section>

            <div class="hidden lg:mt-auto lg:block"></div>
        </main>

        <img
            src="{{ Vite::asset('resources/images/login/edificio-itcj.webp') }}"
            alt=""
            class="hidden lg:sticky lg:top-0 lg:order-1 lg:block lg:h-svh lg:w-full lg:object-cover"
        />
    </div>
</x-core::layouts.guest>
