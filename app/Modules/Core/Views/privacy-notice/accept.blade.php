{{--
    «Aviso de privacidad» (AC-22, supuesto por confirmar; DESIGN.md, «Aviso de privacidad»): la pantalla a la que va el
    alumno que no ha aceptado la versión vigente del aviso. Con la escena del login, como el primer acceso: la credencial
    ya impresa con su ficha, el aviso simplificado en su recuadro (core::privacy-notice.box), «Acepto» y «Salir» (cierra
    la sesión). Datos de AcceptPrivacyNoticePage.
--}}
<x-core::layouts.guest title="Aviso de privacidad" bare>
    <x-core::login.scene>
        <x-core::password.badge
            :credential="$credential"
            printed
            heading="Aviso de privacidad"
            lead="Antes de seguir en Nexus, lee cómo trata tus datos personales el Tecnológico Nacional de México."
        >
            <x-core::privacy-notice.box />

            <p class="text-[14.5px] text-pretty">
                Con «Acepto» confirmas que leíste el aviso de privacidad y aceptas el tratamiento de tus datos personales.
            </p>

            <div class="flex flex-wrap items-center gap-3">
                <form method="POST" action="{{ route('privacy-notice.accept.store') }}" class="min-w-[180px] flex-1">
                    @csrf
                    <x-button type="submit" label="Acepto" icon="o-check" class="btn-primary h-12 w-full text-base" data-testid="privacy-notice-accept" />
                </form>
                <form method="POST" action="{{ route('logout') }}">
                    @csrf
                    <x-button type="submit" label="Salir" icon="o-arrow-left-start-on-rectangle" class="btn-ghost h-12 text-base font-normal" data-testid="privacy-notice-logout" />
                </form>
            </div>
        </x-core::password.badge>
    </x-core::login.scene>
</x-core::layouts.guest>
