{{--
    «Justificaciones» (DESIGN.md, «Cierre de la carga»): pestañas «Por justificar» (primero), «Justificados» y «Todos»
    con sus cifras, la búsqueda «Grupo o materia» y, si la modalidad de la carga usa salones, «Todos · Sin docente · Sin salón» con lo
    que cada uno muestra; todo se filtra en el servidor y se muestra de 50 en 50 («Mostrar 50 más»). Cada grupo: la
    clave en Mono con carrera y semestre, la materia y el horario un bloque por renglón, lo que le falta (insignia en
    warning mientras no se justifica, neutra ya justificado) y la justificación con quién y cuándo. La lista es un
    contenedor: tabla de cinco columnas desde 62rem, la clave a la izquierda y lo demás apilado desde 34rem, todo
    apilado más angosta.

    Quien justifica tiene una casilla por grupo (todo el alto del renglón, también al tacto), «Seleccionar los N de esta
    vista» (los de la pestaña y los filtros, también los que no se muestran todavía; hasta 200) y, con algo elegido, una
    barra pegada abajo con «Justificar N grupos», que abre el modal del motivo. La selección vive en el navegador
    (`wire:model` sin `.live`) hasta justificar, como en Acuerdos. «Justificar», «Editar» o «Ver» abren el drawer del
    grupo en el navegador (`$dispatchTo`), sin volver a pintar la lista.
--}}
<div data-testid="{{ $prefix }}-justifications"
    x-data="{
        viewIds() { return JSON.parse($root.querySelector('[data-view-ids]')?.dataset.viewIds ?? '[]') },
        allChosen() { const ids = this.viewIds(); return ids.length > 0 && ids.every((id) => $wire.selected.includes(id)) },
        toggleAll() { $wire.selected = this.allChosen() ? [] : this.viewIds() },
    }">
    @if ($bulkResult !== null)
        @php($skipped = count($bulkResult['skipped']))
        <section role="status" class="mx-3 mb-3 flex items-start gap-3 rounded-box border border-base-300 py-3 ps-4 pe-2 md:mx-4" data-testid="bulk-result">
            <x-icon :name="$skipped === 0 ? 'o-check-circle' : 'o-exclamation-triangle'" @class(['mt-0.5 h-5 w-5 shrink-0', 'text-success' => $skipped === 0, 'text-warning' => $skipped > 0]) aria-hidden="true" />
            <div class="min-w-0 flex-1">
                <p class="font-medium">Justificados <span class="font-mono">{{ $bulkResult['justified'] }}</span>@if ($skipped > 0)<span class="text-neutral"> · </span>No se justificaron <span class="font-mono">{{ $skipped }}</span>@endif</p>
                @if ($skipped > 0)
                    <ul class="mt-1.5 grid gap-1 text-sm" data-testid="bulk-skipped">
                        @foreach ($bulkResult['skipped'] as $failure)
                            <li class="flex min-w-0 flex-wrap gap-x-2"><span class="font-mono font-semibold">{{ $failure['code'] }}</span><span class="min-w-0 text-neutral">{{ $failure['reason'] }}</span></li>
                        @endforeach
                    </ul>
                @endif
            </div>
            <button type="button" wire:click="dismissBulkResult" class="btn btn-ghost btn-sm btn-square max-md:h-11 max-md:w-11" aria-label="Cerrar el resultado"><x-icon name="o-x-mark" class="h-4 w-4" /></button>
        </section>
    @endif

    @if ($total === 0)
        <p class="px-5 py-4 text-sm text-neutral" data-testid="{{ $prefix }}-no-items">Ningún grupo sin docente ni sin salón.</p>
    @else
        <div class="flex flex-wrap items-end justify-between gap-x-4 gap-y-2 px-3 md:px-4">
            <div class="relative min-w-0 max-w-full overflow-x-auto overflow-y-hidden [scrollbar-width:none]">
                <div role="tablist" aria-label="Justificaciones" class="tabs tabs-border flex-nowrap">
                    @foreach (['pending' => 'Por justificar', 'done' => 'Justificados', 'all' => 'Todos'] as $value => $label)
                        <button type="button" role="tab" aria-selected="{{ $activeTab === $value ? 'true' : 'false' }}" wire:click="$set('tab', '{{ $value }}')"
                            @class(['tab flex-nowrap gap-2 whitespace-nowrap max-md:h-11 max-sm:px-2.5', 'tab-active font-semibold' => $activeTab === $value]) data-testid="{{ $prefix }}-tab-{{ $value }}">
                            {{ $label }} <span @class(['badge badge-sm badge-soft font-mono tabular-nums', 'badge-warning' => $value === 'pending' && $counts['pending'] > 0, 'badge-neutral' => ! ($value === 'pending' && $counts['pending'] > 0)])>{{ $counts[$value] }}</span>
                        </button>
                    @endforeach
                </div>
            </div>
            <label class="input mb-1 w-full max-md:h-11 md:w-64">
                <x-icon name="o-magnifying-glass" class="h-4 w-4 text-neutral" aria-hidden="true" />
                <span class="sr-only">Buscar grupo o materia</span>
                <input type="search" wire:model.live.debounce.300ms="search" placeholder="Grupo o materia" autocomplete="off" data-testid="{{ $prefix }}-search" />
            </label>
        </div>

        @if ($usesRooms || ($canJustify && $viewCount > 0))
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 px-4 pt-3 md:px-5">
                @if ($usesRooms)
                    {{-- El filtro segmentado de referencia (assign/state-filter): tres columnas con la etiqueta sobre la cifra en celular. --}}
                    <div role="group" aria-label="Le falta" class="join grid w-full grid-cols-3 md:flex md:w-auto" data-testid="{{ $prefix }}-lack">
                        @foreach (['' => 'Todos', 'teacher' => 'Sin docente', 'room' => 'Sin salón'] as $option => $optionLabel)
                            <button type="button" wire:click="$set('lack', '{{ $option }}')" aria-pressed="{{ $lack === $option ? 'true' : 'false' }}" data-testid="{{ $prefix }}-lack-{{ $option === '' ? 'todos' : $option }}"
                                @class(['btn join-item px-2 max-md:h-auto max-md:min-h-11 max-md:min-w-0 max-md:flex-col max-md:gap-0 max-md:py-1 max-md:leading-tight md:flex-1 md:gap-1.5 md:whitespace-nowrap md:px-3', 'btn-primary' => $lack === $option])><span class="max-md:max-w-full max-md:truncate">{{ $optionLabel }}</span> <span class="font-mono text-[12.5px] tabular-nums opacity-85">{{ $lackCounts[$option] }}</span></button>
                        @endforeach
                    </div>
                @endif
                @if ($canJustify && $viewCount > 0)
                    @php($chooseLabel = match (true) { $viewCount > $max => "Seleccionar los primeros {$max} de esta vista", $viewCount === 1 => 'Seleccionar el de esta vista', default => "Seleccionar los {$viewCount} de esta vista" })
                    <span hidden data-view-ids="{{ json_encode($viewIds) }}"></span>
                    <button type="button" x-on:click="toggleAll()" class="btn btn-ghost btn-sm max-md:h-11 max-md:min-h-11 md:ms-auto" data-testid="select-view">
                        <x-icon name="o-check-circle" class="h-4 w-4" x-show="! allChosen()" aria-hidden="true" />
                        <x-icon name="o-minus-circle" class="h-4 w-4" x-show="allChosen()" x-cloak aria-hidden="true" />
                        <span x-text="allChosen() ? 'Quitar la selección' : @js($chooseLabel)">{{ $chooseLabel }}</span>
                    </button>
                @endif
            </div>
        @endif

        <div class="@container mt-3 border-t border-base-300" role="list" aria-label="Grupos sin docente o sin salón">
            <div class="hidden border-b border-base-300 bg-base-200 text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral @min-[62rem]:flex" aria-hidden="true">
                @if ($canJustify)<span class="w-11 shrink-0"></span>@endif
                <div @class(['grid flex-1 grid-cols-[6.5rem_minmax(0,1fr)_13rem_minmax(0,1.2fr)_7.5rem] gap-x-4 py-2', 'ps-1 pe-5' => $canJustify, 'px-5' => ! $canJustify])>
                    <span>Grupo</span><span>Materia</span><span>Le falta</span><span>Justificación</span><span></span>
                </div>
            </div>
            @forelse ($rows as $item)
                @php($justified = $item['justification'] !== null)
                <div role="listitem" wire:key="{{ $prefix }}-item-{{ $item['id'] }}" class="flex border-b border-base-300 last:border-b-0" data-code="{{ $item['code'] }}" data-testid="{{ $prefix }}-item-{{ $item['code'] }}">
                    @if ($canJustify)
                        <label class="flex w-11 shrink-0 cursor-pointer justify-center pt-3.5 ps-2">
                            <input type="checkbox" class="checkbox checkbox-sm" value="{{ $item['id'] }}" wire:model="selected" aria-label="Elegir {{ $item['code'] }} · {{ $item['subject_name'] }}" data-testid="select-{{ $item['code'] }}" />
                        </label>
                    @endif
                    <div @class([
                        'grid min-w-0 flex-1 grid-cols-[minmax(0,1fr)_auto] items-start gap-x-4 gap-y-1.5 py-3 @min-[34rem]:grid-cols-[6rem_minmax(0,1fr)_auto] @min-[62rem]:grid-cols-[6.5rem_minmax(0,1fr)_13rem_minmax(0,1.2fr)_7.5rem]',
                        'ps-1 pe-5' => $canJustify,
                        'px-5' => ! $canJustify,
                    ])>
                        <div class="min-w-0 @min-[34rem]:row-span-3 @min-[62rem]:row-span-1">
                            <span class="font-mono text-[15px] font-semibold">{{ $item['code'] }}</span>
                            <span class="block text-[12.5px] text-neutral">{{ $item['career_code'] }} · Sem. {{ $item['semester'] }}</span>
                        </div>
                        <div class="col-start-1 min-w-0 @min-[34rem]:col-start-2 @min-[34rem]:row-start-1 @min-[62rem]:col-start-auto @min-[62rem]:row-start-auto">
                            <span class="block">{{ $item['subject_name'] }} <span class="whitespace-nowrap font-mono text-[12.5px] text-neutral">{{ $item['subject_code'] }}</span></span>
                            <span class="flex flex-wrap gap-x-3 font-mono text-[12.5px] text-neutral">
                                @foreach (explode(' · ', $item['schedule']) as $block)
                                    <span class="whitespace-nowrap">{{ $block }}</span>
                                @endforeach
                            </span>
                        </div>
                        <div class="col-start-1 flex flex-wrap gap-1.5 @min-[34rem]:col-start-2 @min-[62rem]:col-start-auto">
                            @if ($item['lacks_teacher'])
                                <x-core::status-badge :tone="$justified ? 'neutral' : 'warning'" icon="o-user" label="Sin docente" />
                            @endif
                            @if ($item['roomless_sessions'] > 0)
                                <x-core::status-badge :tone="$justified ? 'neutral' : 'warning'" icon="o-map-pin" :label="'Sin salón · '.$item['roomless_sessions'].' '.($item['roomless_sessions'] === 1 ? 'sesión' : 'sesiones')" />
                            @endif
                        </div>
                        <div class="col-start-1 min-w-0 @min-[34rem]:col-start-2 @min-[62rem]:col-start-auto">
                            @if ($justified)
                                <p class="text-sm">{{ $item['justification'] }}</p>
                                <p class="text-[12.5px] text-neutral">{{ $item['justified_by'] ?? 'Un usuario ya dado de baja' }} · <span class="whitespace-nowrap font-mono tabular-nums">{{ $item['justified_at']?->format('d/m/Y G:i') }}</span></p>
                            @else
                                <p @class(['text-sm text-neutral', 'hidden @min-[62rem]:block' => $withActions])>Sin justificación</p>
                            @endif
                        </div>
                        <div class="col-start-2 row-span-4 row-start-1 flex justify-end @min-[34rem]:col-start-3 @min-[34rem]:row-span-3 @min-[34rem]:row-start-1 @min-[62rem]:col-start-auto @min-[62rem]:row-span-1 @min-[62rem]:row-start-auto">
                            @if ($withActions)
                                @php($open = "\$dispatchTo('staffing::components.closing-justify-drawer', 'open-justification', { id: '{$item['id']}' })")
                                @if ($canJustify)
                                    <x-button
                                        :label="$justified ? 'Editar' : 'Justificar'"
                                        :icon="$justified ? 'o-pencil-square' : 'o-pencil'"
                                        @class(['btn-sm max-md:h-11 max-md:min-h-11', 'btn-ghost' => $justified, 'btn-outline' => ! $justified])
                                        wire:click="{{ $open }}"
                                        :aria-label="($justified ? 'Editar la justificación de ' : 'Justificar ').$item['code']"
                                        data-testid="justify-{{ $item['code'] }}"
                                    />
                                @else
                                    <x-button label="Ver" icon="o-eye" class="btn-ghost btn-sm max-md:h-11 max-md:min-h-11" wire:click="{{ $open }}" :aria-label="'Ver la justificación de '.$item['code']" data-testid="view-{{ $item['code'] }}" />
                                @endif
                            @endif
                        </div>
                    </div>
                </div>
            @empty
                <p class="px-5 py-4 text-sm text-neutral">Ningún grupo con ese filtro.</p>
            @endforelse
        </div>

        @if ($viewCount > count($rows))
            @php($next = min($viewCount - count($rows), 50))
            <div class="flex flex-wrap items-center justify-between gap-x-4 gap-y-2 border-t border-base-300 px-4 py-2.5 md:px-5">
                <p class="text-sm text-neutral" data-testid="{{ $prefix }}-shown">Mostrando <span class="font-mono">{{ count($rows) }}</span> de <span class="font-mono">{{ $viewCount }}</span></p>
                <x-button :label="'Mostrar '.$next.' más'" icon="o-chevron-down" class="btn-outline btn-sm max-md:h-11 max-md:min-h-11 max-md:w-full" wire:click="showMore" spinner="showMore" data-testid="{{ $prefix }}-show-more" />
            </div>
        @endif

        @if ($canJustify)
            <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-2 rounded-b-box border-t border-base-300 bg-base-100 px-4 py-2.5 md:px-5" data-testid="bulk-bar"
                x-show="$wire.selected.length > 0" @if ($selected === []) x-cloak @endif>
                <span class="me-auto text-sm"><span class="font-mono font-semibold" x-text="$wire.selected.length">{{ count($selected) }}</span> <span x-text="$wire.selected.length === 1 ? 'elegido' : 'elegidos'">{{ count($selected) === 1 ? 'elegido' : 'elegidos' }}</span></span>
                <button type="button" class="btn btn-ghost max-md:h-11 max-md:min-h-11" x-on:click="$wire.selected = []" data-testid="bulk-clear">Quitar</button>
                <button type="button" class="btn btn-primary max-md:h-11 max-md:min-h-11 max-md:w-full" wire:click="askBulk" x-bind:disabled="$wire.selected.length > {{ $max }}" aria-describedby="bulk-limit" data-testid="bulk-justify">
                    <x-icon name="o-pencil" class="h-4 w-4" aria-hidden="true" />
                    <span x-text="'Justificar ' + $wire.selected.length + ($wire.selected.length === 1 ? ' grupo' : ' grupos')">Justificar {{ count($selected) }} {{ count($selected) === 1 ? 'grupo' : 'grupos' }}</span>
                </button>
                <p id="bulk-limit" class="basis-full text-sm text-error" role="alert" x-show="$wire.selected.length > {{ $max }}" x-cloak>Se justifican hasta {{ $max }} a la vez: quita algunos de la selección.</p>
                @error('selected')
                    <p class="basis-full text-sm text-error" role="alert" data-testid="bulk-error">{{ $message }}</p>
                @enderror
            </div>

            <x-modal wire:model="showBulk" :title="'Justificar '.count($selected).' '.(count($selected) === 1 ? 'grupo' : 'grupos')" box-class="max-w-xl" data-testid="bulk-modal">
                <div class="space-y-4">
                    <p class="text-sm">Cada grupo queda con este motivo y con quién y cuándo; después se edita uno por uno.</p>
                    <x-textarea label="Justificación" wire:model="bulkReason" rows="4" placeholder="Por ejemplo: Docente de nuevo ingreso, contratación en trámite." data-testid="bulk-reason" />
                </div>
                <x-slot:actions>
                    <x-button label="Cancelar" class="btn-ghost" @click="$wire.showBulk = false" />
                    <x-button :label="'Justificar '.count($selected)" icon="o-pencil" class="btn-primary" wire:click="justifySelected" spinner="justifySelected" data-testid="confirm-bulk" />
                </x-slot:actions>
            </x-modal>
        @endif
    @endif
</div>
