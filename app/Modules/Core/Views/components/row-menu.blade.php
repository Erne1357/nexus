{{--
    Menú «Acciones» de un renglón de catálogo (DESIGN.md, "Acciones de fila"): la acción frecuente
    queda visible en el renglón y lo demás se pliega aquí. Es un `popover` del navegador, que se pinta
    en la capa superior y por eso la caja con overflow de la tabla ni el pie de la página lo cortan;
    Esc o un clic fuera lo cierran y el foco vuelve al botón. Se abre debajo del botón y, si no cabe,
    arriba. Con `compact` (la tabla), entre 768 y 1199 px el botón queda solo con el icono. Los
    elementos son <x-core::row-menu-item>.

    Con `icon` y `label` es el menú «Más acciones» de una barra de herramientas (DESIGN.md, "Anatomía de
    una pantalla de catálogo"): el botón es solo ese icono, del alto de la acción principal, y `label` es su
    nombre accesible.

    <x-core::row-menu :name="$campus->code" :compact="$inTable"> ...elementos... </x-core::row-menu>
    <x-core::row-menu icon="o-ellipsis-vertical" label="Más acciones"> ...elementos... </x-core::row-menu>
--}}
@props(['name' => null, 'compact' => false, 'icon' => null, 'label' => null])

<div
    x-data="{
        place() {
            const trigger = this.$refs.trigger.getBoundingClientRect()
            const menu = this.$refs.menu
            const margin = 8

            menu.style.maxHeight = `${window.innerHeight - margin * 2}px`

            const width = menu.offsetWidth
            const height = menu.offsetHeight
            const below = trigger.bottom + 4
            const above = trigger.top - height - 4

            menu.style.left = `${Math.max(margin, Math.min(trigger.right - width, window.innerWidth - width - margin))}px`
            menu.style.top = `${Math.max(margin, below + height > window.innerHeight - margin && above >= margin ? above : Math.min(below, window.innerHeight - height - margin))}px`
        },
        opened(event) {
            if (event.newState !== 'open') {
                return
            }

            this.place()
            this.$nextTick(() => this.items()[0]?.focus())
        },
        items() {
            return Array.from(this.$refs.menu.querySelectorAll('[role=menuitem]:not([aria-disabled=true])'))
        },
        move(step) {
            const items = this.items()
            const next = step === 0 ? 0 : (items.indexOf(document.activeElement) + step + items.length) % items.length

            items[step === -2 ? items.length - 1 : next]?.focus()
        },
        close() {
            if (this.$refs.menu.matches(':popover-open')) {
                this.$refs.menu.hidePopover()
            }
        },
    }"
    x-id="['row-menu', 'row-menu-trigger']"
    x-on:scroll.window.capture="if (! $refs.menu.contains($event.target)) close()"
    x-on:resize.window="close()"
    {{ $attributes->class(['inline-block']) }}
>
    <button
        type="button"
        x-ref="trigger"
        :id="$id('row-menu-trigger')"
        :popovertarget="$id('row-menu')"
        aria-haspopup="menu"
        aria-label="{{ $label ?? 'Acciones de '.$name }}"
        @class(['btn btn-ghost', 'btn-sm' => $icon === null, 'btn-square' => $icon !== null])
    >
        @if ($icon !== null)
            <x-icon :name="$icon" class="h-5 w-5" aria-hidden="true" />
        @else
            @if ($compact)
                <span class="min-[1200px]:hidden"><x-icon name="o-ellipsis-horizontal" class="h-4 w-4" aria-hidden="true" /></span>
            @endif
            <span @class(['inline-flex items-center gap-1', 'max-[1200px]:hidden' => $compact])>
                Acciones
                <x-icon name="o-chevron-down" class="h-4 w-4" aria-hidden="true" />
            </span>
        @endif
    </button>

    <div
        x-ref="menu"
        :id="$id('row-menu')"
        popover
        role="menu"
        :aria-labelledby="$id('row-menu-trigger')"
        x-on:toggle="opened($event)"
        x-on:keydown.arrow-down.prevent="move(1)"
        x-on:keydown.arrow-up.prevent="move(-1)"
        x-on:keydown.home.prevent="move(0)"
        x-on:keydown.end.prevent="move(-2)"
        class="inset-auto m-0 w-max min-w-56 max-w-[calc(100vw-1rem)] overflow-y-auto rounded-box border border-base-300 bg-base-100 p-1 text-base-content shadow-lg"
    >
        {{ $slot }}
    </div>
</div>
