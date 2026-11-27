{{-- La cabecera de la activación del acceso (DESIGN.md, "Pantalla de acceso"): los logos del TecNM (azul espacio) y del
ITCJ (rojo Tec) pegados arriba y, debajo, la marca Nexus centrada. El login lleva los logos en placas
(core::login.plates) y la marca en la cabecera de su credencial (tarea 1.23). --}}
<div data-login-logos class="flex items-center justify-between gap-5">
    <span
        role="img"
        aria-label="Tecnológico Nacional de México"
        class="block aspect-[469/200] h-[58px] bg-primary [mask:var(--logo)_center/contain_no-repeat] lg:h-[clamp(55px,7.9vh,77px)]"
        style="--logo: url('{{ Vite::asset('resources/images/login/tecnm.webp') }}')"
    ></span>
    <span
        role="img"
        aria-label="Instituto Tecnológico de Ciudad Juárez"
        class="block aspect-[465/220] h-[66px] bg-accent [mask:var(--logo)_center/contain_no-repeat] lg:h-[clamp(61px,8.8vh,84px)]"
        style="--logo: url('{{ Vite::asset('resources/images/login/itcj.webp') }}')"
    ></span>
</div>

<h1 class="mt-3 mb-2 flex justify-center lg:my-auto lg:py-[clamp(14px,2.6vh,30px)]">
    <span class="sr-only">Nexus</span>
    <x-core::login.mark data-login-mark class="w-[min(76vw,300px)] lg:w-[clamp(270px,calc(22vh+170px),430px)]" />
</h1>
