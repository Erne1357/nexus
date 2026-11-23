{{--
    Cambiar la contraseña (tarea 1.30; DESIGN.md, "Pantallas de contraseña"), con la credencial que se imprime con cada
    requisito (core::password.badge). En el primer acceso cuelga en la escena del login (sin la aplicación, como pide
    EnsurePasswordIsChanged); en un cambio voluntario, de la banda de la aplicación. Recién guardada, la credencial
    impresa lleva el acuse (core::password.receipt) y el botón para seguir. Datos de ChangePasswordPage.
--}}
@if ($firstAccess)
    <x-core::layouts.guest title="Cambiar contraseña" bare>
        <x-core::login.scene>
            <x-core::password.badge
                :credential="$credential"
                :requirements="$requirements"
                heading="Cambia tu contraseña"
                lead="Es tu primer acceso: elige una propia."
            >
                @include('core::auth.partials.change-password-form')
            </x-core::password.badge>
        </x-core::login.scene>
    </x-core::layouts.guest>
@else
    <x-layouts::app title="Cambiar contraseña">
        <div class="flex justify-center pt-[clamp(28px,5vh,56px)] pb-12 desktop:justify-end desktop:pe-[clamp(0px,3vw,64px)]" style="--hare: url('{{ Vite::asset('resources/images/liebre-512.png') }}')">
            <x-core::password.lanyard>
                @if ($receipt)
                    <x-core::password.badge :credential="$credential" printed heading="Tu contraseña se actualizó." :lead="$continueTo ? 'Ya puedes usar Nexus con ella.' : 'Las demás sesiones abiertas se cerraron.'">
                        <x-button
                            :label="$continueTo ? 'Entrar a Nexus' : 'Ir al inicio'"
                            :link="$continueTo ?? route('home')"
                            icon-right="o-arrow-right"
                            class="btn-primary h-12 text-base"
                            data-testid="password-continue"
                        />

                        <x-slot:receipt>
                            <x-core::password.receipt :receipt="$receipt" notch="bg-base-200" />
                        </x-slot:receipt>
                    </x-core::password.badge>
                @else
                    <x-core::password.badge
                        :credential="$credential"
                        :requirements="$requirements"
                        heading="Cambia tu contraseña"
                        lead="Al guardarla se cierran tus demás sesiones abiertas."
                    >
                        @include('core::auth.partials.change-password-form')
                    </x-core::password.badge>
                @endif
            </x-core::password.lanyard>
        </div>
    </x-layouts::app>
@endif
