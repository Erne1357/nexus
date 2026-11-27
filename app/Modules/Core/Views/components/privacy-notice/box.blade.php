{{--
    El aviso de privacidad simplificado del TecNM en un recuadro con desplazamiento, para aceptarlo sin salir de la
    pantalla (AC-22; DESIGN.md, «Aviso de privacidad»): el texto tal cual (core::privacy-notice.simplified) a 13.5 px,
    con su propio desplazamiento y foco de teclado, y debajo el enlace al aviso integral en la página pública, en otra
    pestaña para no perder lo escrito. Lo usan la pantalla de cambio de contraseña y la de «Aviso de privacidad».
--}}
<div {{ $attributes->class('flex flex-col gap-2') }}>
    <div
        role="region"
        aria-label="Aviso de privacidad simplificado"
        tabindex="0"
        data-testid="privacy-notice-box"
        class="max-h-[min(38vh,300px)] overflow-y-auto overscroll-contain rounded-lg border border-base-300 bg-base-200 px-4 py-3.5 text-[13.5px]/[1.55] [scrollbar-color:var(--color-neutral)_transparent] [scrollbar-width:thin] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary"
    >
        <x-core::privacy-notice.simplified />
    </div>

    <a href="{{ route('privacy-notice') }}#integral" target="_blank" rel="noopener" class="inline-flex items-center gap-1.5 self-start py-1 text-[14px] font-medium text-primary underline decoration-base-300 underline-offset-[3px] hover:decoration-current">
        <x-icon name="o-arrow-top-right-on-square" class="h-4 w-4" />Lee el aviso integral<span class="sr-only"> (se abre en otra pestaña)</span>
    </a>
</div>
