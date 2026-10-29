{{--
    El menú que se transforma (DESIGN.md). Todo el estado viene de <body data-menu="closed|open">, que
    cambia el componente Alpine nexusShell, y de las variables de geometría del shell que el layout
    declara en <body> por ancho (--shell-top, --menu-w, --panel-w, --panel-clip). Las variantes:
    sin prefijo es escritorio cerrado; in-data-[menu=open]: es abierto; max-desktop: es tableta y
    max-md: es el celular, donde el menú solo existe abierto (la hoja). Cada área envuelve sus
    elementos en un grupo con display: contents para que todos sean hijos de la misma retícula: así un
    icono o una entrada pueden viajar de la franja a su columna.
--}}
<div class="text-sm/[1.45]">
    {{-- Velo detrás del menú abierto; un clic fuera lo cierra. --}}
    <div
        aria-hidden="true"
        x-on:click="closeMenu()"
        class="pointer-events-none fixed inset-x-0 top-(--shell-top) bottom-0 z-[3] bg-band/35 opacity-0 transition-opacity duration-[350ms] ease-settle motion-reduce:transition-none in-data-[menu=open]:pointer-events-auto in-data-[menu=open]:opacity-100"
    ></div>

    {{-- Fondo del panel: se despliega y se recoge como cortina. --}}
    <div
        aria-hidden="true"
        class="fixed top-(--shell-top) bottom-0 left-0 z-[4] w-(--panel-w) border-e border-base-300 bg-base-100 max-md:border-e-0 [clip-path:var(--panel-clip)] transition-[clip-path] duration-[420ms] ease-settle motion-reduce:transition-none in-data-[menu=open]:[clip-path:inset(0)] in-data-[menu=open]:duration-[550ms] in-data-[menu=open]:ease-open"
    ></div>

    {{-- Fondo de la franja de iconos de área. --}}
    <div
        aria-hidden="true"
        class="fixed top-(--shell-top) bottom-0 left-0 z-[4] w-16 bg-secondary transition-opacity delay-[120ms] duration-[250ms] motion-reduce:transition-none max-md:hidden in-data-[menu=open]:opacity-0 in-data-[menu=open]:delay-0 in-data-[menu=open]:duration-200"
    ></div>

    <nav
        id="nexus-menu"
        aria-label="Navegación principal"
        class="fixed top-(--shell-top) left-0 z-[5] w-(--menu-w) [display:var(--menu-display)] auto-rows-[40px] grid-cols-(--menu-cols) content-start py-3.5 [--current-icon:var(--color-base-100)] [--menu-cols-open:repeat(5,minmax(0,1fr))] [--menu-cols:64px_224px] [--menu-display:grid] max-desktop:[--menu-cols-open:repeat(3,minmax(0,1fr))] max-desktop:[--menu-cols:64px] max-md:[--menu-display:none] in-data-[menu=open]:grid in-data-[menu=open]:w-(--panel-w) in-data-[menu=open]:auto-rows-[38px] in-data-[menu=open]:grid-cols-(--menu-cols-open) in-data-[menu=open]:gap-x-[18px] in-data-[menu=open]:px-5 in-data-[menu=open]:py-[18px] in-data-[menu=open]:[--current-icon:var(--color-secondary)] max-md:bottom-0 max-md:auto-rows-auto max-md:grid-cols-[40px_minmax(0,1fr)] max-md:gap-x-2 max-md:gap-y-0.5 max-md:overflow-y-auto max-md:overscroll-contain max-md:px-3.5 max-md:pt-3.5 max-md:pb-8"
    >
        <label
            data-menu-flip="search"
            class="col-span-full row-[1/span_2] hidden h-[50px] items-center gap-2.5 self-start rounded-lg border-2 border-primary bg-base-100 px-3.5 ring-4 ring-primary/10 in-data-[menu=open]:flex max-md:row-auto max-md:mb-2"
        >
            <x-icon name="o-magnifying-glass" class="size-[18px] text-secondary-muted" />

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

        @foreach ($areas as $area)
            <div
                role="group"
                aria-label="{{ $area['area']->label() }}"
                data-menu-area="{{ $area['area']->value }}"
                @if ($area['current']) data-current @endif
                class="contents"
            >
                {{-- Icono del área: en la franja cerrado, encabezado de su columna abierto. Un clic abre el menú. --}}
                <span
                    data-menu-flip="icon"
                    data-keep
                    aria-hidden="true"
                    title="{{ $area['area']->label() }}"
                    style="{{ $area['style'] }}"
                    x-on:click="openMenu()"
                    class="grid h-10 w-11 place-items-center place-self-center rounded-lg text-secondary-muted transition-colors duration-300 ease-settle [grid-column:1] [grid-row:var(--c)] [--open-col:var(--c)] [--open-row:3] in-data-current:bg-(--current-icon) in-data-current:text-base-content max-desktop:[--open-col:var(--col-t)] max-desktop:[--open-row:var(--base-t)] in-data-[menu=closed]:cursor-pointer in-data-[menu=open]:w-[30px] in-data-[menu=open]:justify-self-start in-data-[menu=open]:[grid-column:var(--open-col)] in-data-[menu=open]:[grid-row:var(--open-row)] max-md:mt-3 max-md:w-10 max-md:[grid-area:auto]"
                >
                    <x-icon :name="$area['area']->icon()" class="size-[18px]" />
                </span>

                {{-- Nombre del área: el grupo ya lo lleva en aria-label. --}}
                <span
                    data-menu-flip="name"
                    @if ($area['current']) data-keep @endif
                    aria-hidden="true"
                    style="{{ $area['style'] }}"
                    class="hidden self-center px-[22px] text-xs font-bold tracking-[.1em] whitespace-nowrap text-neutral uppercase [grid-column:2] [grid-row:1] [--open-col:var(--c)] [--open-row:3] desktop:in-data-current:block max-desktop:tracking-[.05em] max-desktop:[--open-col:var(--col-t)] max-desktop:[--open-row:var(--base-t)] in-data-[menu=open]:block in-data-[menu=open]:truncate in-data-[menu=open]:ps-[38px] in-data-[menu=open]:pe-0 in-data-[menu=open]:text-base-content in-data-[menu=open]:[grid-column:var(--open-col)] in-data-[menu=open]:[grid-row:var(--open-row)] max-md:mt-3 max-md:p-0 max-md:tracking-[.08em] max-md:[grid-area:auto]"
                >{{ $area['area']->label() }}</span>

                @foreach ($area['entries'] as $entry)
                    <a
                        href="{{ route($entry['item']->route) }}"
                        wire:navigate
                        data-menu-flip="entry"
                        data-menu-entry
                        data-label="{{ $entry['item']->label }}"
                        @if ($area['current']) data-keep @endif
                        @if ($entry['active']) aria-current="page" @endif
                        style="{{ $entry['style'] }}"
                        class="mx-3 my-0.5 hidden min-w-0 items-center gap-2.5 rounded-md px-2.5 whitespace-nowrap text-base-content [grid-column:2] [grid-row:calc(var(--i)_+_1)] [--open-col:var(--c)] [--open-row:calc(3_+_var(--i))] hover:bg-base-200 focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary aria-[current=page]:bg-secondary aria-[current=page]:font-bold aria-[current=page]:before:size-1.5 aria-[current=page]:before:flex-none aria-[current=page]:before:rounded-full aria-[current=page]:before:bg-accent desktop:in-data-current:flex max-desktop:[--open-col:var(--col-t)] max-desktop:[--open-row:calc(var(--base-t)_+_var(--i))] in-data-[menu=open]:m-0 in-data-[menu=open]:flex in-data-[menu=open]:[grid-column:var(--open-col)] in-data-[menu=open]:[grid-row:var(--open-row)] max-md:col-span-full max-md:min-h-11 max-md:px-3 max-md:[grid-row:auto]"
                    >
                        <span class="truncate">{{ $entry['item']->label }}</span>
                    </a>
                @endforeach
            </div>
        @endforeach
    </nav>
</div>
