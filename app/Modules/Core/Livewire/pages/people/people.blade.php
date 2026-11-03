{{--
    «Personas y puestos» (DESIGN.md, «Personas y puestos»), con la anatomía de una pantalla de catálogo: encabezado,
    barra con la búsqueda y el filtro «Puesto» (sin acción principal) y una tabla con el usuario en Mono, la persona,
    un chip por puesto vigente y el estado; «Ver puestos» abre la ficha. Debajo de 768 px la tabla cede su lugar a
    renglones apilados, que no repiten los data-testid de la tabla.
--}}
<div>
    <x-header title="Personas y puestos" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Quién ocupa cada puesto de Administración, Subdirección Académica y Coordinación, y desde cuándo.
        </x-slot:subtitle>
    </x-header>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input
                name="search"
                wire:model.live.debounce.300ms="search"
                placeholder="Número o nombre"
                aria-label="Buscar persona"
                icon="o-magnifying-glass"
                clearable
            />
        </x-slot:search>

        <x-select wire:model.live="role" aria-label="Puesto" :options="$roles" placeholder="Puesto" />
    </x-core::catalog-toolbar>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$people"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_username', $user)
                <span class="font-mono text-[15px] tabular-nums">{{ $user->username }}</span>
            @endscope

            @scope('cell_name', $user)
                <span @class(['font-semibold' => $user->is_active])>{{ $user->name }}</span>
            @endscope

            @scope('cell_roles', $user, $summaries)
                <div class="flex flex-wrap gap-1.5">
                    @foreach ($summaries[$user->id] as $summary)
                        <span class="rounded border border-base-300 bg-base-200 px-2 py-0.5 text-sm">{{ $summary }}</span>
                    @endforeach
                </div>
            @endscope

            @scope('cell_is_active', $user)
                @include('core::people.status-cell', ['user' => $user])
            @endscope

            @scope('actions', $user)
                <div class="flex justify-end">
                    <a href="{{ route('core.people.show', $user->id) }}" wire:navigate class="btn btn-ghost btn-sm" data-testid="view-{{ $user->username }}" aria-label="Ver puestos de {{ $user->name }}">Ver puestos</a>
                </div>
            @endscope

            <x-slot:empty>
                <x-icon name="o-users" label="Nadie coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="people-cards">
            @foreach ($people as $user)
                <li wire:key="person-card-{{ $user->id }}" @class(['space-y-1.5 px-4 py-3', 'text-neutral' => ! $user->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span class="font-mono text-[15px] tabular-nums">{{ $user->username }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $user->is_active])>{{ $user->name }}</span>
                    </div>

                    @include('core::people.status-cell', ['user' => $user])

                    @if ($summaries[$user->id] !== [])
                        <div class="flex flex-wrap gap-1.5">
                            @foreach ($summaries[$user->id] as $summary)
                                <span class="rounded border border-base-300 bg-base-200 px-2 py-0.5 text-sm">{{ $summary }}</span>
                            @endforeach
                        </div>
                    @endif

                    <a href="{{ route('core.people.show', $user->id) }}" wire:navigate class="btn btn-ghost btn-sm -ms-3 max-md:h-11">Ver puestos</a>
                </li>
            @endforeach

            @if ($people->isEmpty())
                <li class="px-4 py-6 text-center text-neutral">Nadie coincide con la búsqueda.</li>
            @endif
        </ul>

        @if ($people->isNotEmpty() || $people->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($people->isNotEmpty())
                    <p>Personas <span class="font-mono">{{ $people->firstItem() }}–{{ $people->lastItem() }}</span> de <span class="font-mono">{{ $people->total() }}</span></p>
                @endif

                {{ $people->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>
</div>
