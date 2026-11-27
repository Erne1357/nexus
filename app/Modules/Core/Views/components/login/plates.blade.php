{{-- Los logos del login con credencial (tarea 1.23; DESIGN.md, "Pantalla de acceso"): el del TecNM y el del ITCJ en
blanco, cada uno en una placa clavada y un poco inclinada, la del TecNM en azul espacio y la del ITCJ en rojo Tec. Son
las mismas máscaras de una tinta de core::login.brand. resources/js/login.js las clava una tras otra la primera vez
(data-login-plate, con su inclinación en data-tilt). --}}
<div data-login-logos class="relative z-10 flex items-center justify-between gap-5">
    @foreach ([
        ['label' => 'Tecnológico Nacional de México', 'logo' => 'resources/images/login/tecnm.webp', 'tilt' => -1.6, 'plate' => 'bg-primary -rotate-[1.6deg]', 'pin' => 'bg-secondary', 'size' => 'aspect-[469/200] h-12 lg:h-[clamp(52px,7.5vh,72px)]'],
        ['label' => 'Instituto Tecnológico de Ciudad Juárez', 'logo' => 'resources/images/login/itcj.webp', 'tilt' => 1.3, 'plate' => 'bg-accent rotate-[1.3deg]', 'pin' => 'bg-primary', 'size' => 'aspect-[465/220] h-[53px] lg:h-[clamp(57px,8.3vh,79px)]'],
    ] as $plate)
        <span
            data-login-plate
            data-tilt="{{ $plate['tilt'] }}"
            class="{{ $plate['plate'] }} relative block rounded-[3px] px-3.5 pt-[17px] pb-3 shadow-[0_1px_2px_rgb(0_0_0/0.14),0_12px_22px_-14px_rgb(9_45_72/0.55)] lg:px-[22px] lg:pt-[21px] lg:pb-4"
        >
            <i aria-hidden="true" class="{{ $plate['pin'] }} absolute top-[5px] left-1/2 size-2 -translate-x-1/2 rounded-full shadow-[0_1px_1.5px_rgb(0_0_0/0.4),inset_0_-1px_0_rgb(0_0_0/0.18)] lg:top-[7px] lg:size-[9px]"></i>
            <span
                role="img"
                aria-label="{{ $plate['label'] }}"
                class="{{ $plate['size'] }} block bg-white [mask:var(--logo)_center/contain_no-repeat]"
                style="--logo: url('{{ Vite::asset($plate['logo']) }}')"
            ></span>
        </span>
    @endforeach
</div>
