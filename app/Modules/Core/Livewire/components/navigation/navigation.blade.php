{{--
    El menú que se transforma (DESIGN.md). Todo el estado viene de <body data-menu="closed|open">, que
    cambia el componente Alpine nexusShell, y de las variables de geometría del shell que el layout
    declara en <body> por ancho (--shell-top, --menu-w, --panel-w, --panel-clip). Las variantes: sin
    prefijo es escritorio cerrado; in-data-[menu=open]: es abierto; max-desktop: es tableta y max-md: es el
    celular, donde el menú solo existe abierto (la hoja). Las variantes de ancho solo cambian variables o
    ganan a propósito: en la hoja del celular mandan sobre las del menú abierto.

    Cerrado hay dos contenedores: la franja de iconos (data-menu-rail), fija, y la columna del área actual
    (data-menu-column), que se desplaza sola y se desvanece arriba y abajo cuando hay más. Abierto, los dos
    pasan a display: contents y todo es hijo de la misma retícula, donde cada elemento va al lugar que le
    dio MenuGrid ($place): así un icono o una entrada pueden viajar de la franja o la columna a su lugar. Los
    nombres, los encabezados y las entradas van encima de los iconos (relative z-[1]): un icono que viaja
    pasa por debajo del texto, no lo tapa.
--}}
@php
    $place = 'in-data-[menu=open]:[grid-column:var(--open-col)/span_var(--open-span)] in-data-[menu=open]:[grid-row:var(--open-row)] [--open-col:var(--oc)] [--open-row:var(--or)] [--open-span:var(--os)] max-desktop:[--open-col:var(--oc3)] max-desktop:[--open-row:var(--or3)] max-desktop:[--open-span:var(--os3)] max-md:[grid-area:auto]';
@endphp
<div class="text-sm/[1.45]">
    {{-- Velo detrás del menú abierto; un clic fuera lo cierra. --}}
    <div
        aria-hidden="true"
        x-on:click="closeMenu()"
        class="pointer-events-none fixed inset-x-0 top-(--shell-top) bottom-0 z-[22] bg-band/35 opacity-0 transition-opacity duration-[350ms] ease-settle motion-reduce:transition-none in-data-[menu=open]:pointer-events-auto in-data-[menu=open]:opacity-100"
    ></div>

    {{-- Fondo del panel: se despliega y se recoge como cortina. --}}
    <div
        aria-hidden="true"
        class="fixed top-(--shell-top) bottom-0 left-0 z-[23] w-(--panel-w) border-e border-base-300 bg-base-100 max-md:border-e-0 [clip-path:var(--panel-clip)] transition-[clip-path] duration-[420ms] ease-settle motion-reduce:transition-none in-data-[menu=open]:[clip-path:inset(0)] in-data-[menu=open]:duration-[550ms] in-data-[menu=open]:ease-open"
    ></div>

    {{-- Fondo de la franja de iconos. --}}
    <div
        aria-hidden="true"
        class="fixed top-(--shell-top) bottom-0 left-0 z-[23] w-16 bg-secondary transition-opacity delay-[120ms] duration-[250ms] motion-reduce:transition-none max-md:hidden in-data-[menu=open]:opacity-0 in-data-[menu=open]:delay-0 in-data-[menu=open]:duration-200"
    ></div>

    <nav
        id="nexus-menu"
        aria-label="Navegación principal"
        data-grid-first-row="{{ $grid['firstRow'] }}"
        data-grid-column-rows="{{ $grid['columnRows'] }}"
        x-on:mouseover="showWhy($event.target)"
        x-on:mouseleave="hideWhy()"
        x-on:focusin="showWhy($event.target)"
        x-on:focusout="hideWhy()"
        x-on:scroll.passive="hideWhy()"
        class="fixed top-(--shell-top) bottom-0 left-0 z-[24] w-(--menu-w) [display:var(--menu-display)] grid-cols-(--menu-cols) grid-rows-[minmax(0,1fr)] [--current-icon:var(--color-base-100)] [--home-bg:transparent] [--home-current-bg:var(--color-base-100)] [--menu-cols-open:repeat(5,minmax(0,1fr))] [--menu-cols:64px_224px] [--menu-display:grid] max-desktop:[--menu-cols-open:repeat(3,minmax(0,1fr))] max-desktop:[--menu-cols:64px] max-md:[--menu-display:none] in-data-[menu=open]:grid in-data-[menu=open]:w-(--panel-w) in-data-[menu=open]:grid-cols-(--menu-cols-open) in-data-[menu=open]:grid-rows-none in-data-[menu=open]:auto-rows-[36px] in-data-[menu=open]:content-start in-data-[menu=open]:gap-x-[18px] in-data-[menu=open]:overflow-y-auto in-data-[menu=open]:overscroll-contain in-data-[menu=open]:px-5 in-data-[menu=open]:pt-[18px] in-data-[menu=open]:pb-8 in-data-[menu=open]:[--current-icon:transparent] in-data-[menu=open]:[--home-bg:var(--color-base-100)] in-data-[menu=open]:[--home-current-bg:var(--color-secondary)] max-md:grid-cols-1 max-md:auto-rows-auto max-md:gap-y-0.5 max-md:px-3.5 max-md:pt-3.5 max-md:pb-8 max-md:[--home-bg:transparent]"
    >
        <label
            data-menu-flip="search"
            class="col-span-full row-[1/span_2] hidden h-[50px] items-center gap-2.5 self-start rounded-lg border-2 border-primary bg-base-100 px-3.5 ring-4 ring-primary/10 in-data-[menu=open]:ms-[62px] in-data-[menu=open]:flex max-md:row-auto max-md:ms-0 max-md:mb-2"
        >
            <x-icon name="o-magnifying-glass" class="w-[18px] h-[18px] text-secondary-muted" />

            <input
                id="nexus-menu-search"
                type="search"
                autocomplete="off"
                aria-label="Buscar en el menú"
                placeholder="Busca una pantalla…"
                x-on:input="filterMenu($event.target.value)"
                x-on:keydown.enter.prevent="openFirstMatch()"
                class="min-w-0 flex-1 bg-transparent text-base text-base-content caret-primary outline-none placeholder:text-secondary-muted [&::-webkit-search-cancel-button]:hidden"
            />

            <kbd class="font-mono text-xs font-semibold text-secondary-muted">Esc</kbd>
        </label>

        <p data-menu-empty hidden class="col-span-full row-start-3 self-center text-neutral max-md:row-auto max-md:px-3 max-md:py-2">Sin resultados</p>

        {{-- La franja: Inicio, su filete y el icono de cada área. Cerrada no se desplaza. --}}
        <div data-menu-rail class="col-start-1 row-start-1 flex min-h-0 flex-col items-center py-3.5 in-data-[menu=open]:contents">
            {{--
                Inicio: cerrado, el primer icono; abierto, el cuadro a la izquierda del buscador; en la hoja,
                el primer renglón, con su nombre. No es de ninguna área.
            --}}
            <a
                href="{{ $home['href'] }}"
                wire:navigate
                data-menu-home
                data-menu-flip="icon"
                data-keep
                aria-label="Inicio"
                data-tip="Inicio"
                @if ($home['current']) aria-current="page" @endif
                class="tooltip tooltip-right grid h-10 w-11 flex-none place-items-center rounded-lg bg-(--home-bg) text-secondary-muted transition-colors duration-300 ease-settle hover:text-base-content focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary aria-[current=page]:bg-(--home-current-bg) aria-[current=page]:text-base-content in-data-[menu=open]:col-start-1 in-data-[menu=open]:row-start-1 in-data-[menu=open]:h-[50px] in-data-[menu=open]:w-[50px] in-data-[menu=open]:self-start in-data-[menu=open]:border in-data-[menu=open]:border-base-content/20 in-data-[menu=open]:text-base-content in-data-[menu=open]:before:hidden in-data-[menu=open]:after:hidden in-data-[menu=open]:aria-[current=page]:border-secondary max-md:[grid-area:auto] max-md:flex max-md:h-11 max-md:w-auto max-md:justify-start max-md:gap-3 max-md:rounded-md max-md:border-0 max-md:px-3 max-md:aria-[current=page]:font-bold"
            >
                <x-icon name="o-home" class="w-[18px] h-[18px]" />
                <span class="hidden max-md:inline">Inicio</span>
            </a>

            <span aria-hidden="true" class="my-1.5 h-px w-7 flex-none bg-base-content/15 in-data-[menu=open]:hidden"></span>

            @foreach ($areas as $area)
                {{--
                    Icono del área: en la franja cerrado, encabezado de su columna abierto. Lleva a la última
                    página que visitaste del área o a su principal; no abre el menú (eso es de la liebre).
                    Cerrado, su nombre sale en un tooltip a la derecha. En la hoja no va: el nombre lleva el suyo.
                    El del área actual lleva data-area-current y no data-current, que Livewire pone y quita solo
                    en los enlaces wire:navigate según la página.
                --}}
                <div data-menu-area="{{ $area['area']->value }}" class="contents">
                    <a
                        href="{{ $area['href'] }}"
                        wire:navigate
                        data-menu-flip="icon"
                        data-keep
                        @if ($area['marked']) data-area-current @endif
                        aria-label="{{ $area['area']->label() }}{{ $area['hidden'] ? ', oculta para los demás' : '' }}"
                        data-tip="{{ $area['area']->label() }}{{ $area['hidden'] ? ', oculta para los demás' : '' }}"
                        style="{{ $area['iconStyle'] }}"
                        class="tooltip tooltip-right grid h-10 w-11 flex-none place-items-center rounded-lg text-secondary-muted transition-colors duration-300 ease-settle hover:text-base-content focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary data-area-current:bg-(--current-icon) data-area-current:text-base-content in-data-[menu=open]:h-9 in-data-[menu=open]:w-[30px] in-data-[menu=open]:justify-self-start in-data-[menu=open]:self-center in-data-[menu=open]:before:hidden in-data-[menu=open]:after:hidden {{ $place }} max-md:hidden"
                    >
                        <x-icon :name="$area['area']->icon()" class="w-[18px] h-[18px]" />

                        {{-- El área entera oculta para los demás (MN-02): un ojo tachado en la esquina, solo cerrado. --}}
                        @if ($area['hidden'])
                            <span data-menu-hidden-mark aria-hidden="true" class="absolute right-px bottom-0 grid h-[18px] w-[18px] place-items-center rounded-full border border-base-content/20 bg-base-100 text-secondary-muted in-data-[menu=open]:hidden">
                                <x-icon name="o-eye-slash" class="w-3 h-3" />
                            </span>
                        @endif
                    </a>
                </div>
            @endforeach
        </div>

        {{-- La columna: cerrada, el área actual, que se desplaza sola; abierta, todas las áreas. --}}
        <div
            data-menu-column
            x-on:scroll.passive="fadeColumn($el); hideWhy()"
            x-on:resize.window="fadeColumn($el)"
            class="isolate col-start-2 row-start-1 min-h-0 [display:var(--column-display)] flex-col overflow-y-auto overscroll-contain pt-3.5 pb-7 [--column-display:flex] [scrollbar-color:var(--color-base-300)_transparent] [scrollbar-width:thin] data-fade:[mask-image:linear-gradient(to_bottom,transparent,black_var(--fade-top),black_calc(100%_-_var(--fade-bottom)),transparent)] max-desktop:[--column-display:none] in-data-[menu=open]:contents"
        >
            @foreach ($areas as $area)
                <div
                    role="group"
                    aria-label="{{ $area['area']->label() }}"
                    data-menu-area="{{ $area['area']->value }}"
                    @if ($area['current']) data-current @endif
                    @if ($area['headings']) data-headings @endif
                    @if ($area['hidden']) aria-describedby="{{ $area['hidden']['id'] }}" @endif
                    class="contents"
                >
                    @if ($area['hidden'])
                        <span hidden id="{{ $area['hidden']['id'] }}">{{ $area['hidden']['why'] }}</span>
                    @endif

                    {{-- Nombre del área: el grupo ya lo lleva en aria-label. Abierto cubre su icono: no toma los clics. --}}
                    <span
                        data-menu-flip="name"
                        @if ($area['current']) data-keep @endif
                        aria-hidden="true"
                        style="{{ $area['nameStyle'] }}"
                        class="pointer-events-none relative z-[1] hidden h-10 flex-none items-center px-[22px] text-xs font-bold tracking-[.1em] whitespace-nowrap text-neutral uppercase in-data-current:flex in-data-[menu=open]:flex in-data-[menu=open]:h-9 in-data-[menu=open]:border-b in-data-[menu=open]:border-base-300 in-data-[menu=open]:ps-[38px] in-data-[menu=open]:pe-0 in-data-[menu=open]:text-base-content {{ $place }} max-desktop:tracking-[.05em] max-md:h-auto max-md:gap-3 max-md:border-0 max-md:px-1 max-md:pt-4 max-md:pb-1.5 max-md:tracking-[.08em]"
                    >
                        {{-- En la hoja no va la franja: el nombre lleva el icono del área. MaryUI pone `inline` al icono, así que lo oculta su caja. --}}
                        <span class="hidden flex-none max-md:inline-flex"><x-icon :name="$area['area']->icon()" class="w-[18px] h-[18px]" /></span>
                        <span class="truncate">{{ $area['area']->label() }}</span>

                        @if ($area['hidden'])
                            <x-core::menu-hidden-badge label="Oculta" :why="$area['hidden']['why']" class="pointer-events-auto ms-1 tracking-[.01em] normal-case" />
                        @endif
                    </span>

                    @foreach ($area['groups'] as $group)
                        {{-- Encabezado del grupo, solo si el área los lleva (MN-01). --}}
                        @if ($group['group'] !== null)
                            <span
                                data-menu-flip="heading"
                                data-menu-group="{{ $group['group']->value }}"
                                @if ($area['current']) data-keep @endif
                                style="{{ $group['style'] }}"
                                class="relative z-[1] mt-1.5 hidden h-[30px] min-w-0 flex-none items-end px-[22px] pb-[5px] text-[11.5px] font-medium tracking-[.09em] whitespace-nowrap text-neutral uppercase in-data-current:flex in-data-[menu=open]:mt-0 in-data-[menu=open]:flex in-data-[menu=open]:h-9 in-data-[menu=open]:px-2.5 in-data-[menu=open]:pb-1.5 {{ $place }} max-md:h-auto max-md:px-3 max-md:pt-2.5 max-md:pb-1"
                            >
                                <span class="truncate">{{ $group['group']->label() }}</span>

                                @if ($group['hidden'])
                                    <x-core::menu-hidden-badge label="Oculto" :why="$group['hidden']['why']" class="ms-2 tracking-[.01em] normal-case" />
                                    <span class="sr-only">, oculto para los demás: {{ $group['hidden']['reasons'] }}</span>
                                @endif
                            </span>

                            @if ($group['hidden']['note'] ?? null)
                                <span data-menu-note aria-hidden="true" class="hidden px-3 pb-1.5 text-[12.5px]/4 text-neutral max-md:block">{{ $group['hidden']['note'] }}</span>
                            @endif
                        @endif

                        @foreach ($group['entries'] as $entry)
                            <a
                                href="{{ route($entry['item']->route) }}"
                                wire:navigate
                                data-menu-flip="entry"
                                data-menu-entry
                                data-label="{{ $entry['item']->label }}"
                                @if ($area['current']) data-keep @endif
                                @if ($entry['active']) data-active aria-current="{{ $entry['page'] ? 'page' : 'true' }}" @endif
                                @if ($entry['hidden']) aria-describedby="{{ $entry['hidden']['id'] }}" data-why="{{ $entry['hidden']['why'] }}" @endif
                                style="{{ $entry['style'] }}"
                                class="relative z-[1] mx-3 my-px hidden h-9 min-w-0 flex-none items-center gap-2.5 rounded-md px-2.5 whitespace-nowrap text-base-content hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary data-active:bg-secondary data-active:font-bold data-active:before:size-1.5 data-active:before:flex-none data-active:before:rounded-full data-active:before:bg-accent in-data-current:flex in-data-[menu=open]:m-0 in-data-[menu=open]:flex {{ $place }} max-md:h-auto max-md:min-h-11 max-md:px-3"
                            >
                                <span class="truncate">{{ $entry['item']->label }}</span>

                                {{-- Cerrada en escritorio la columna mide 224 px: la insignia queda solo con su icono. --}}
                                @if ($entry['hidden']['badge'] ?? false)
                                    <x-core::menu-hidden-badge label="Oculta" :compact="true" class="ms-auto in-data-active:bg-base-100" />
                                @endif
                            </a>

                            @if ($entry['hidden'])
                                @if ($entry['hidden']['note'])
                                    <span data-menu-note aria-hidden="true" class="-mt-1 hidden px-3 pb-2.5 text-[12.5px]/4 text-neutral max-md:block">{{ $entry['hidden']['note'] }}</span>
                                @endif

                                <span hidden id="{{ $entry['hidden']['id'] }}">{{ $entry['hidden']['why'] }}</span>
                            @endif
                        @endforeach
                    @endforeach
                </div>
            @endforeach
        </div>
    </nav>

    {{--
        El motivo flotante de lo oculto para los demás (MN-02): uno solo, fuera de la columna para que su
        desplazamiento no lo corte. Sale al pasar el cursor o al enfocar algo con data-why: a la derecha con el menú
        cerrado, debajo abierto o en el celular. Es solo para la vista: el lector de pantalla tiene el motivo en
        aria-describedby.
    --}}
    <div
        data-menu-why-tip
        aria-hidden="true"
        class="pointer-events-none fixed top-0 left-0 z-[24] flex max-w-[300px] items-start gap-2 rounded-md border border-band-muted/25 bg-band px-2.5 py-[7px] text-[12.5px]/[1.35] text-band-content opacity-0 shadow-float transition-opacity duration-[120ms] motion-reduce:transition-none data-on:opacity-100"
    >
        <x-icon name="o-eye-slash" class="mt-px w-3.5 h-3.5 flex-none text-band-muted" />
        <span data-menu-why-text></span>
    </div>
</div>
