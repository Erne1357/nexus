{{-- La cabecera de la credencial del login (tarea 1.23; DESIGN.md, "Pantalla de acceso"): azul espacio con el ojal, el
cuadro rojo Tec con la liebre y la marca, y debajo la franja roja de 3 px. La del frente nombra la pantalla (h1) y lleva
las referencias con que resources/js/login.js la vuelve banda al entrar; la del reverso repite la marca sin sus ids.
Con `standalone`, la credencial vive en otra página y trae su propia marca. --}}
@props(['front' => false, 'standalone' => false])

<header @if ($front) x-ref="header" @endif class="relative flex items-center gap-4 bg-primary px-[22px] pt-7 pb-[18px]">
    <span aria-hidden="true" class="absolute top-[9px] left-1/2 -ml-[29px] h-[9px] w-[58px] rounded-full bg-base-100"></span>
    <span @if ($front) x-ref="hare" @endif aria-hidden="true" class="grid aspect-[9/8] w-[clamp(62px,15.5cqw,80px)] flex-none place-items-center bg-accent">
        <span class="block h-[78%] w-[64%] bg-white mask-(--hare) mask-contain mask-center mask-no-repeat"></span>
    </span>
    @if ($front)
        <h1 id="login-badge-title" class="flex">
            <span class="sr-only">Nexus</span>
            <x-core::login.mark x-ref="mark" data-login-mark class="w-[min(52cqw,300px)]" />
        </h1>
    @else
        <x-core::login.mark :reuse="! $standalone" class="w-[min(52cqw,300px)]" />
    @endif
</header>
<div @if ($front) x-ref="stripe" @endif aria-hidden="true" class="h-[3px] flex-none bg-accent"></div>
