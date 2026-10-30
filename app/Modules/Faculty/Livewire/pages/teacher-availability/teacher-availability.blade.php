{{--
    Editor de disponibilidad (DESIGN.md, "Cuadrícula día x hora"; variante A, «herramienta y pintar»): el enlace de
    regreso a la ficha; el encabezado con el periodo, el docente, el horario de la cuadrícula y «Copiar de <periodo>»;
    las cifras (disponible, preferido y no disponible contra las horas base del periodo); las herramientas, la
    cuadrícula y su leyenda y, a la derecha desde 1400 px (debajo antes), los rangos por día; y la barra de guardar,
    pegada al pie mientras hay bloques sin guardar. Todo lo que cambia al pintar es del componente Alpine
    `availabilityEditor` (resources/js/app.js), con `wire:ignore`: Livewire pinta la página una vez y save() y
    copyPrevious() no la vuelven a pintar. Sin periodo activo, un aviso para elegirlo.
--}}
<div class="space-y-5">
    <a href="{{ route('faculty.teachers.show', ['teacher' => $teacher->id, 'tab' => 'disponibilidad']) }}" wire:navigate class="inline-flex max-w-full items-center gap-1.5 font-medium text-primary">
        <x-icon name="o-chevron-left" class="w-4 h-4 shrink-0" /><span class="truncate">{{ $teacher->name }}</span>
    </a>

    @if ($term === null)
        <h1 class="text-[1.625rem] font-bold leading-tight md:text-3xl">Disponibilidad</h1>
        <div class="flex flex-col items-center gap-2 rounded-box border border-dashed border-base-300 bg-base-100 px-5 py-10 text-center" data-testid="no-term">
            <x-icon name="o-calendar-days" class="h-6 w-6 text-neutral" aria-hidden="true" />
            <p class="font-semibold">Sin periodo activo</p>
            <p class="max-w-prose text-neutral">Elige un periodo en tu credencial: la disponibilidad de {{ $teacher->name }} es de un periodo.</p>
        </div>
    @else
        <div wire:ignore x-data="availabilityEditor(@js($editor))" x-on:keydown.window="toolKey($event)" class="space-y-5">
            <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
                <div class="min-w-0 flex-[1_1_30rem]">
                    <h1 class="flex flex-wrap items-center gap-3 text-[1.625rem] font-bold leading-tight md:text-3xl">
                        Disponibilidad
                        <x-core::status-badge tone="primary" :label="$term->name" />
                    </h1>
                    <p class="mt-2 flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                        <span>Docente <span class="font-mono text-base-content">{{ $teacher->employee_number }}</span> <span class="font-medium text-base-content">{{ $teacher->name }}</span></span>
                        <span>{{ $campus ? 'Horario de '.$campus->name : 'Horario presencial por omisión' }} <span class="font-medium text-base-content">{{ $grid['schedule'] }}</span></span>
                        <span>Bloques de <span class="font-medium text-base-content">{{ $blocks }}</span></span>
                    </p>
                </div>

                <div class="md:ms-auto max-md:w-full">
                    @if ($copyReason === null)
                        <x-button :label="'Copiar de '.$previous->name" icon="o-document-duplicate" class="btn-outline max-md:w-full" x-on:click="copyPrevious()" x-bind:disabled="copying" data-testid="copy-previous" />
                    @else
                        <span class="tooltip tooltip-left max-md:tooltip-bottom max-md:block" data-tip="{{ $copyReason }}">
                            <x-button :label="$previous ? 'Copiar de '.$previous->name : 'Copiar del periodo anterior'" icon="o-document-duplicate" class="btn-outline max-md:w-full" disabled aria-label="Copiar del periodo anterior: {{ $copyReason }}" data-testid="copy-previous" />
                        </span>
                        <span class="sr-only">{{ $copyReason }}</span>
                    @endif
                </div>
            </div>

            <div class="grid grid-cols-2 gap-px overflow-hidden rounded-box border border-base-300 bg-base-300 desktop:grid-cols-4" aria-live="polite" data-testid="availability-figures">
                @foreach ([
                    ['Disponible', 'figures.available', $grid['summary']['available'], 'h de '.$grid['summary']['total']],
                    ['Preferido', 'figures.preferred', $grid['summary']['preferred'], 'h'],
                    ['No disponible', 'figures.unavailable', $grid['summary']['unavailable'], 'h'],
                    ['Horas base · '.$term->name, null, $grid['summary']['baseHours'], 'h · '.$grid['summary']['positions'].' '.($grid['summary']['positions'] === 1 ? 'plaza' : 'plazas')],
                ] as [$label, $binding, $value, $unit])
                    <div class="flex min-w-0 flex-col gap-1 bg-base-100 px-5 py-3.5 max-md:px-3.5 max-md:py-3">
                        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</span>
                        <span class="flex flex-wrap items-baseline gap-x-2 font-mono text-2xl font-semibold tabular-nums max-md:text-[21px]">
                            <span @if ($binding) x-text="hours({{ $binding }})" @endif>{{ $value }}</span>
                            <span class="font-sans text-sm font-normal text-neutral">{{ $unit }}</span>
                        </span>
                    </div>
                @endforeach
            </div>

            <div class="grid items-start gap-x-5 gap-y-4 min-[1400px]:grid-cols-[minmax(0,1fr)_22.5rem]">
                <div class="min-w-0 space-y-3">
                    <div class="join max-md:flex max-md:w-full" role="radiogroup" aria-label="Herramienta" x-on:keydown="toolArrow($event)">
                        @foreach ([
                            ['unavailable', 'No disponible', 'N', \App\Modules\Faculty\Enums\AvailabilityKind::Unavailable, null],
                            ['preferred', 'Preferido', 'P', \App\Modules\Faculty\Enums\AvailabilityKind::Preferred, null],
                            ['', 'Borrar', 'B', null, 'o-backspace'],
                        ] as [$value, $label, $key, $kind, $icon])
                            <button type="button" role="radio" data-tool="{{ $value }}" aria-keyshortcuts="{{ $key }}"
                                aria-checked="{{ $value === 'unavailable' ? 'true' : 'false' }}" tabindex="{{ $value === 'unavailable' ? 0 : -1 }}"
                                x-bind:aria-checked="tool === '{{ $value }}' ? 'true' : 'false'" x-bind:tabindex="tool === '{{ $value }}' ? 0 : -1"
                                x-on:click="tool = '{{ $value }}'"
                                class="btn join-item h-10 gap-2 border-base-300 ps-2 pe-3 font-medium whitespace-nowrap shadow-none max-md:flex-auto max-md:gap-1.5 max-md:px-1.5 aria-checked:btn-primary not-aria-checked:bg-base-100 not-aria-checked:hover:bg-base-200">
                                <x-faculty::availability.swatch :kind="$kind" :icon="$icon" />
                                {{ $label }}
                                <kbd class="kbd kbd-sm font-mono text-[11.5px] max-md:hidden">{{ $key }}</kbd>
                            </button>
                        @endforeach
                    </div>

                    <x-faculty::availability.grid :grid="$grid" interactive :label="'Disponibilidad de '.$teacher->name.' en '.$term->name" />

                    <x-faculty::availability.legend />
                </div>

                <section class="overflow-hidden rounded-box border border-base-300 bg-base-100 min-[1400px]:sticky min-[1400px]:top-21" aria-labelledby="h-ranges">
                    <header class="border-b border-base-300 px-4 py-3">
                        <h2 id="h-ranges" class="text-lg font-bold">Por día</h2>
                    </header>
                    <x-faculty::availability.ranges :grid="$grid" interactive />
                </section>
            </div>

            <div x-cloak x-show="pending > 0" x-transition.opacity.duration.200ms
                class="sticky bottom-3.5 z-10 flex flex-wrap items-center gap-x-3 gap-y-2 rounded-box border border-base-300 bg-base-100 py-2.5 ps-4 pe-2.5 shadow-float max-md:bottom-2.5 max-md:p-2.5"
                data-testid="save-bar">
                <span class="inline-flex items-center gap-2 font-semibold" aria-live="polite">
                    <span class="inline-block h-2 w-2 rounded-full bg-info" aria-hidden="true"></span>
                    <span x-text="pending === 1 ? '1 bloque sin guardar' : pending + ' bloques sin guardar'"></span>
                </span>
                <div class="ms-auto flex gap-2 max-md:ms-0 max-md:w-full">
                    <x-button label="Descartar" icon="o-arrow-uturn-left" class="btn-ghost whitespace-nowrap max-md:flex-auto max-md:px-3 max-md:[&_svg]:hidden" x-on:click="discard()" x-bind:disabled="saving" data-testid="discard" />
                    <x-button label="Guardar disponibilidad" icon="o-check" class="btn-primary whitespace-nowrap max-md:flex-auto max-md:px-3" x-on:click="save()" x-bind:disabled="saving" data-testid="save-availability" />
                </div>
            </div>
        </div>
    @endif
</div>
