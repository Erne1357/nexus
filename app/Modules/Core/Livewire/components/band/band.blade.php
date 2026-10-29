{{--
    La banda (DESIGN.md, "Estructura" y "Responsivo"). Es fija arriba con su franja roja; la tarjeta
    de la credencial flota debajo. El estado (menú y credencial) es del componente Alpine nexusShell
    de <body>; aquí solo se le llama. Sin prefijo es escritorio; max-desktop: es tableta (sin el área en
    la ruta y la credencial con avatar y nombre) y max-md: es el celular (liebre, «Nexus», lupa y
    avatar).
--}}
<div class="sticky top-0 z-[7] text-sm/[1.45]">
    <header class="flex h-16 items-center gap-[18px] bg-band pe-5 text-band-content max-md:h-14 max-md:gap-3 max-md:pe-2.5">
        <button
            type="button"
            id="nexus-hare"
            data-testid="hare"
            aria-label="Abrir el menú completo"
            aria-expanded="false"
            aria-controls="nexus-menu"
            x-on:click="toggleMenu()"
            class="grid h-16 w-[72px] flex-none cursor-pointer place-items-center bg-accent focus-visible:outline-2 focus-visible:-outline-offset-7 focus-visible:outline-accent-content max-md:h-14 max-md:w-14"
        >
            <span
                data-hare-mark
                aria-hidden="true"
                style="--hare: url('{{ Vite::asset('resources/images/liebre-512.png') }}')"
                class="block size-[46px] bg-accent-content mask-(--hare) mask-contain mask-center mask-no-repeat max-md:size-[38px]"
            ></span>
        </button>

        <a href="{{ route('home') }}" wire:navigate class="rounded-sm text-xl font-bold tracking-[-0.01em] focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-band-content max-md:text-lg">Nexus</a>

        @if ($pageTitle !== null)
            <nav aria-label="Ruta" class="flex min-w-0 items-center gap-2 text-[15px] whitespace-nowrap text-band-muted max-md:hidden">
                @if ($areaLabel !== null)
                    <span class="max-desktop:hidden">{{ $areaLabel }}</span>
                    <x-icon name="o-chevron-right" class="size-4 max-desktop:hidden" />
                @endif

                <span aria-current="page" class="truncate font-medium text-band-content">{{ $pageTitle }}</span>
            </nav>
        @endif

        <span class="flex-1"></span>

        <button
            type="button"
            data-testid="menu-search-trigger"
            aria-controls="nexus-menu"
            x-on:click="openMenu()"
            class="flex h-10 w-[clamp(260px,26vw,520px)] cursor-pointer items-center gap-2.5 overflow-hidden rounded-lg border border-band-content/18 bg-band-content/8 px-3 whitespace-nowrap text-band-muted transition-[opacity,visibility] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-band-content motion-reduce:transition-none max-desktop:w-[220px] max-md:hidden in-data-[menu=open]:pointer-events-none in-data-[menu=open]:invisible in-data-[menu=open]:opacity-0"
        >
            <x-icon name="o-magnifying-glass" class="size-[18px] flex-none" />
            Buscar o ir a…
            <kbd class="ms-auto rounded border border-band-content/25 px-1.5 py-px font-mono text-xs font-semibold text-band-content">Ctrl K</kbd>
        </button>

        <button
            type="button"
            aria-label="Buscar"
            aria-controls="nexus-menu"
            x-on:click="openMenu()"
            class="hidden size-11 flex-none cursor-pointer place-items-center rounded-lg border border-band-content/20 transition-[opacity,visibility] duration-200 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-band-content motion-reduce:transition-none max-md:grid in-data-[menu=open]:pointer-events-none in-data-[menu=open]:invisible in-data-[menu=open]:opacity-0"
        >
            <x-icon name="o-magnifying-glass" class="size-[18px]" />
        </button>

        <div x-on:click.outside="closeCard(false)" class="min-w-0">
            <button
                type="button"
                id="nexus-credential-toggle"
                data-testid="credential"
                aria-haspopup="dialog"
                aria-controls="nexus-credential"
                aria-expanded="false"
                x-bind:aria-expanded="cardOpen ? 'true' : 'false'"
                x-on:click="toggleCard()"
                class="flex h-[46px] max-w-full min-w-0 cursor-pointer items-center gap-2.5 rounded-full border border-band-content/20 ps-1.5 pe-3 text-start hover:bg-band-content/10 focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-band-content aria-expanded:bg-band-content/10 max-md:h-11 max-md:px-[5px]"
            >
                <span aria-hidden="true" class="grid size-[34px] flex-none place-items-center rounded-full bg-secondary text-[13px] font-bold text-secondary-content">{{ $initials }}</span>

                <span class="flex min-w-0 flex-col leading-tight max-md:sr-only">
                    <span class="truncate font-bold">{{ $name }}</span>
                    @if ($summary !== '')
                        <span class="text-[12.5px] whitespace-nowrap text-band-muted max-desktop:hidden">{{ $summary }}</span>
                    @endif
                </span>

                <x-icon name="o-chevron-down" class="size-[18px] flex-none max-md:hidden" />
            </button>

            <div
                id="nexus-credential"
                role="dialog"
                aria-label="Tu credencial"
                data-open="false"
                x-bind:data-open="cardOpen ? 'true' : 'false'"
                class="pointer-events-none invisible fixed end-5 top-[74px] z-[8] w-[340px] origin-top-right -translate-y-1.5 scale-[.94] overflow-hidden rounded-[10px] bg-base-100 text-base-content opacity-0 shadow-float transition-[opacity,scale,translate,visibility] duration-300 ease-settle motion-reduce:transition-none data-[open=true]:pointer-events-auto data-[open=true]:visible data-[open=true]:translate-y-0 data-[open=true]:scale-100 data-[open=true]:opacity-100 data-[open=true]:duration-[400ms] data-[open=true]:ease-open max-md:inset-x-2 max-md:top-[66px] max-md:w-auto"
            >
                <div data-testid="credential-header" class="flex items-center gap-3 bg-band p-4 text-band-content">
                    <span aria-hidden="true" class="grid size-[46px] flex-none place-items-center rounded-full bg-secondary text-base font-bold text-secondary-content">{{ $initials }}</span>

                    <div class="min-w-0">
                        <span class="block truncate font-bold">{{ $name }}</span>
                        <span class="block text-xs text-band-muted">
                            @if ($roleLabel !== null)
                                {{ $roleLabel }} ·
                            @endif
                            <span class="font-mono">{{ $username }}</span>
                        </span>
                    </div>
                </div>

                <div aria-hidden="true" class="h-[3px] bg-accent"></div>

                <div class="px-4 py-3">
                    <livewire:core::components.context-switcher />
                </div>

                <div class="flex items-center justify-between gap-4 px-4 pt-1 pb-3">
                    <span id="nexus-theme-label">Tema</span>

                    {{-- MaryUI aplica y recuerda el tema; Claro y Oscuro solo le piden cambiarlo. --}}
                    <x-theme-toggle light-theme="nexus" dark-theme="nexus-dark" class="hidden" />

                    <div
                        role="radiogroup"
                        aria-labelledby="nexus-theme-label"
                        data-testid="theme-toggle"
                        x-data="{ theme: document.documentElement.dataset.theme }"
                        x-on:theme-changed.window="theme = $event.detail"
                        class="join"
                    >
                        <input type="radio" name="nexus-theme" value="nexus" aria-label="Claro" x-model="theme" x-on:change="$dispatch('mary-toggle-theme')" class="btn join-item h-[34px] px-3.5 text-sm font-normal not-checked:[--btn-bg:var(--color-base-100)] not-checked:[--btn-border:var(--color-base-300)]" />
                        <input type="radio" name="nexus-theme" value="nexus-dark" aria-label="Oscuro" x-model="theme" x-on:change="$dispatch('mary-toggle-theme')" class="btn join-item h-[34px] px-3.5 text-sm font-normal not-checked:[--btn-bg:var(--color-base-100)] not-checked:[--btn-border:var(--color-base-300)]" />
                    </div>
                </div>

                <div class="grid border-t border-base-300 p-2">
                    <a href="{{ route('user-password.edit') }}" class="rounded-md px-2.5 py-[11px] hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary">Cambiar contraseña</a>

                    <form method="POST" action="{{ route('logout') }}">
                        @csrf

                        <button type="submit" class="w-full cursor-pointer rounded-md px-2.5 py-[11px] text-start hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary">Cerrar sesión</button>
                    </form>
                </div>
            </div>
        </div>
    </header>

    <div aria-hidden="true" class="h-[3px] bg-accent"></div>
</div>
