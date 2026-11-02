{{--
    Anatomía de una pantalla de catálogo (DESIGN.md, "Layout"), la de core::pages.campuses: encabezado, barra de
    herramientas común (búsqueda y una sola acción principal), tabla en una superficie blanca con filete, la clave en
    Mono, el estado con su insignia y «Editar» visible y lo demás en el menú «Acciones». Debajo de 768 px la tabla cede su
    lugar a renglones apilados que no repiten los data-testid de la tabla. Los periodos no tienen filtro de estado.
--}}
<div>
    <x-header title="Periodos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Los periodos escolares con el código del SII y sus fechas; el actual es el de omisión de la credencial.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                wire:model.live.debounce.300ms="search"
                placeholder="Buscar por código o nombre"
                aria-label="Buscar periodos"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-slot:action>
            @if ($canCreate)
                <x-button label="Nuevo periodo" icon="o-plus" class="btn-primary" wire:click="create" spinner="create" />
            @else
                <span class="tooltip tooltip-left" data-tip="Solo el administrador o la subdirección dan de alta periodos.">
                    <x-button label="Nuevo periodo" icon="o-plus" class="btn-primary w-full" disabled />
                </span>
            @endif
        </x-slot:action>
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$terms"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $term)
                <a href="{{ route('core.terms.show', $term->id) }}" wire:navigate @class(['font-mono text-[15px] tabular-nums text-primary hover:underline', 'font-semibold' => $term->is_current])>{{ $term->code }}</a>
            @endscope

            @scope('cell_name', $term)
                <a href="{{ route('core.terms.show', $term->id) }}" wire:navigate @class(['hover:underline', 'font-semibold' => $term->is_current])>{{ $term->name }}</a>
            @endscope

            @scope('cell_starts_on', $term)
                <span class="tabular-nums">{{ $term->starts_on->format('d/m/Y') }}</span>
            @endscope

            @scope('cell_ends_on', $term)
                <span class="tabular-nums">{{ $term->ends_on->format('d/m/Y') }}</span>
            @endscope

            @scope('cell_opened_in', $term)
                @include('core::terms.opened-in', ['term' => $term])
            @endscope

            @scope('cell_is_current', $term)
                @if ($term->is_current)
                    <x-core::status-badge tone="success" label="Actual" />
                @endif
            @endscope

            @scope('actions', $term)
                @include('core::terms.row-actions', ['term' => $term, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-calendar-days" label="Ningún periodo coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="term-cards">
            @foreach ($terms as $term)
                <li wire:key="term-card-{{ $term->id }}" class="space-y-1 px-4 py-3">
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $term->is_current])>{{ $term->code }}</span>
                        <a href="{{ route('core.terms.show', $term->id) }}" wire:navigate @class(['min-w-0 flex-1', 'font-semibold' => $term->is_current])>{{ $term->name }}</a>

                        @if ($term->is_current)
                            <x-core::status-badge tone="success" label="Actual" />
                        @endif
                    </div>

                    <div class="text-neutral">{{ $term->starts_on->format('d/m/Y') }} al {{ $term->ends_on->format('d/m/Y') }}</div>

                    @if ($term->opened_campus_codes !== [])
                        <div class="flex items-center gap-1.5 text-neutral">Abierto en @include('core::terms.opened-in', ['term' => $term])</div>
                    @endif

                    @include('core::terms.row-actions', ['term' => $term, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($terms->isNotEmpty() || $terms->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($terms->isNotEmpty())
                    <p>Periodos <span class="font-mono">{{ $terms->first()->code }}</span>@if ($terms->count() > 1) a <span class="font-mono">{{ $terms->last()->code }}</span> · <span class="font-mono">{{ $terms->firstItem() }}–{{ $terms->lastItem() }}</span>@else · <span class="font-mono">{{ $terms->firstItem() }}</span>@endif de <span class="font-mono">{{ $terms->total() }}</span></p>
                @endif

                {{ $terms->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-modal wire:model="showModal" :title="$editingId === null ? 'Nuevo periodo' : 'Editar periodo'" box-class="max-w-xl" separator>
        <x-form wire:submit="save" no-separator>
            @include('core::terms.form-fields')

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Guardar" type="submit" icon="o-check" class="btn-primary" spinner="save" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
