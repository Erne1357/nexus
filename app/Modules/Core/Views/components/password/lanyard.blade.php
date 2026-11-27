{{-- La cinta azul espacio que sube hasta arriba de la pantalla y el broche que la une a la credencial (DESIGN.md,
"Pantalla de acceso"). En la escena del login sube hasta la orilla; en la aplicación, hasta la banda, que la tapa. --}}
<div {{ $attributes->class('relative w-full max-w-[460px] lg:w-[min(clamp(400px,29vw,560px),100%)] lg:max-w-none') }}>
    <span aria-hidden="true" class="absolute bottom-[calc(100%+8px)] left-1/2 -ml-1.5 h-screen w-3 bg-primary"></span>
    <span aria-hidden="true" class="absolute -top-3.5 left-1/2 z-10 -ml-[15px] h-7 w-[30px]">
        <span class="absolute inset-x-0 top-0 h-3.5 rounded-sm bg-neutral"></span>
        <span class="absolute top-2.5 left-1/2 -ml-1.5 h-[18px] w-3 rounded-b-[3px] bg-neutral"></span>
    </span>

    {{ $slot }}
</div>
