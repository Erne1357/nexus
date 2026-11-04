{{--
    «Organigrama» (DESIGN.md, «Organigrama»), la maqueta B: encabezado; la barra común con la búsqueda (Alpine, sin ir
    al servidor: atenúa las cajas que no coinciden), «Mostrar inactivas», el aviso de departamentos académicos sin jefe
    y «Nueva unidad»; el diagrama de cajas (core::org-chart.box y core::org-chart.branch), con la Dirección arriba, el
    primer nivel en un renglón y los demás colgados de rieles, todo con utilidades de tokens; y el panel de la unidad
    elegida (core::components.org-unit-panel), a la derecha desde 1400 px y debajo antes. Debajo de 768 px el primer
    nivel también cuelga de un riel: el diagrama es una lista.

    Alpine guarda la unidad elegida (`selected`, con el marco `ring-2 ring-primary`) y avisa al panel con
    `org-unit-picked`; debajo de 1400 px lleva la vista al panel. Las hijas del panel piden elegir con `org-unit-select`
    y el panel avisa los cambios con `org-chart-changed`, que también vuelve a pintar el diagrama.
--}}
@php
    $root = $chart->roots[0] ?? null;
    $headless = $chart->headless === 1 ? '1 departamento académico sin jefe' : $chart->headless.' departamentos académicos sin jefe';
@endphp

<div
    x-data="{
        q: '',
        selected: @js($root?->id),
        fold(text) {
            return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim();
        },
        matches(search) {
            const q = this.fold(this.q);

            return q === '' || search.includes(q);
        },
        pick(id) {
            this.selected = id;
            this.$wire.dispatch('org-unit-picked', { id });

            if (! window.matchMedia('(min-width: 1400px)').matches) {
                document.getElementById('org-unit-panel')?.scrollIntoView({
                    behavior: window.matchMedia('(prefers-reduced-motion: reduce)').matches ? 'auto' : 'smooth',
                    block: 'start',
                });
            }
        },
    }"
    x-on:org-unit-select.window="pick($event.detail.id)"
    x-on:org-chart-changed.window="selected = $event.detail.id ?? selected"
>
    <x-header title="Organigrama" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            La estructura del Instituto de un vistazo. Toca una unidad para ver su titular, sus puestos y sus acciones. Los departamentos académicos (con candado) se editan en Académico.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input x-model="q" placeholder="Nombre o clave" aria-label="Buscar unidad" icon="o-magnifying-glass" data-testid="org-search" />
        </x-slot:search>

        <x-checkbox wire:model.live="showInactive" name="showInactive" :label="'Mostrar inactivas ('.$chart->inactive.')'" />

        @if ($chart->headless > 0)
            <p class="flex items-center gap-1.5 text-sm text-neutral md:w-auto!" data-testid="org-headless">
                <x-icon name="o-exclamation-triangle" class="h-4 w-4 shrink-0 text-warning" aria-hidden="true" />{{ $headless }}
            </p>
        @endif

        <x-slot:action>
            <x-button label="Nueva unidad" icon="o-plus" class="btn-primary" x-on:click="$dispatch('org-unit-create')" data-testid="org-new-unit" />
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="grid gap-5 min-[1400px]:grid-cols-[minmax(0,1fr)_22rem]">
        <section aria-label="Diagrama del organigrama" class="min-w-0 self-start rounded-box border border-base-300 bg-base-100" data-testid="org-chart">
            <div class="overflow-x-auto px-3 pt-4 pb-1 md:px-5 md:pt-6">
                @foreach ($chart->roots as $node)
                    <div @class([
                        'relative md:flex md:justify-center',
                        'md:pb-6 md:after:absolute md:after:bottom-0 md:after:left-1/2 md:after:h-6 md:after:border-s-2 md:after:border-primary/40' => $node->children !== [],
                    ])>
                        <div class="md:w-60">
                            @include('core::org-chart.box', ['node' => $node, 'level' => 0])
                        </div>
                    </div>

                    @if ($node->children !== [])
                        <ul
                            data-testid="org-level-1"
                            class="max-md:ms-2 max-md:mt-2 max-md:grid max-md:gap-2 max-md:border-s-2 max-md:border-primary/40 max-md:ps-4 md:flex md:justify-center md:gap-4"
                        >
                            @foreach ($node->children as $child)
                                <li
                                    wire:key="org-{{ $child->id }}"
                                    class="relative min-w-0 max-md:before:absolute max-md:before:-left-4 max-md:before:top-6 max-md:before:w-3.5 max-md:before:border-t-2 max-md:before:border-primary/40 max-md:last:after:absolute max-md:last:after:-left-[1.125rem] max-md:last:after:top-[1.625rem] max-md:last:after:-bottom-px max-md:last:after:w-0.5 max-md:last:after:bg-base-100 md:w-60 md:min-w-44 md:pt-6 md:before:absolute md:before:top-0 md:before:left-1/2 md:before:h-6 md:before:border-s-2 md:before:border-primary/40 md:after:absolute md:after:top-0 md:after:-left-2 md:after:-right-2 md:after:border-t-2 md:after:border-primary/40 md:first:after:left-1/2 md:last:after:right-1/2 md:only:after:hidden"
                                >
                                    @include('core::org-chart.box', ['node' => $child, 'level' => 1])
                                    @include('core::org-chart.branch', ['nodes' => $child->children, 'level' => 2])
                                </li>
                            @endforeach
                        </ul>
                    @endif
                @endforeach
            </div>

            <p class="flex flex-wrap items-center gap-x-1.5 gap-y-1 px-3 pt-3 pb-4 text-sm text-neutral md:px-5" data-testid="org-legend">
                <x-icon name="o-lock-closed" class="h-3.5 w-3.5" aria-hidden="true" />Se edita en Académico ·
                <x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" /><span class="font-mono">n</span> personas en la unidad y en las que cuelgan de ella
            </p>
        </section>

        <livewire:core::components.org-unit-panel />
    </div>
</div>
