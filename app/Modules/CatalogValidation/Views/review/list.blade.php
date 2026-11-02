{{--
    La lista de un catálogo (`?catalogo=`), con la anatomía de las pantallas de catálogo (DESIGN.md, "Anatomía de una
    pantalla de catálogo"): enlace de regreso al tablero, el bloque de la revisión (estado, quién validó, Validar o
    Reabrir y los contadores), la barra de herramientas común (búsqueda y filtros; sin acción principal: Validar vive
    en el bloque), la tabla en una superficie con filete y, debajo de 768 px, renglones apilados que no repiten los
    data-testid. «Editar» abre el drawer (catalog-validation::review.drawer); lo demás va en «Acciones».
--}}
@php($review = $current['review'])
@php($catalogValue = $review->catalog->value)
<div class="space-y-4">
    <a href="{{ route('catalog-validation.review', ['department' => $department->id]) }}" wire:navigate class="inline-flex items-center gap-1.5 font-medium text-primary" data-testid="back-to-board">
        <x-icon name="o-chevron-left" class="h-4 w-4" aria-hidden="true" />Validación de catálogos
    </a>

    <section class="space-y-3.5 rounded-box border border-base-300 bg-base-100 p-4" data-testid="review-block">
        <div class="flex flex-wrap items-start justify-between gap-x-4 gap-y-3">
            <div>
                <h2 class="text-2xl font-bold leading-tight">{{ $review->catalog->label() }}</h2>
                <div class="mt-1.5 flex flex-wrap items-center gap-x-3 gap-y-1.5">
                    <x-core::status-badge :tone="$review->status->tone()" :label="$review->status->label()" />
                    <span class="flex items-center gap-1.5 text-sm text-neutral"><x-icon :name="$review->catalog->isGeneral() ? 'o-building-library' : 'o-map-pin'" class="h-4 w-4" aria-hidden="true" />{{ $review->catalog->isGeneral() ? 'General · todo el Tec' : $review->campus?->name }}</span>
                    @include('catalog-validation::review.who', ['review' => $review])
                </div>
            </div>
            <div class="flex w-full gap-2 md:w-auto">
                @include('catalog-validation::review.actions', ['card' => $current])
            </div>
        </div>

        @foreach ($current['reopenVetoes'] as $veto)
            <p class="text-xs text-neutral">{{ $veto }}</p>
        @endforeach

        @unless ($current['canValidate'] || $current['canReopen'])
            <p class="text-sm text-neutral">Solo lectura: validar, corregir y observar es del jefe del departamento. Tú puedes marcar observaciones como resueltas.</p>
        @endunless

        @include('catalog-validation::review.counters', ['summary' => $current['summary'], 'inline' => true])
    </section>

    <x-core::catalog-toolbar>
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" :placeholder="$review->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers ? 'Número o nombre' : 'Clave o nombre'" aria-label="Buscar registros" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        <x-select wire:model.live="flagFilter" aria-label="Observación" :options="$flagFilters" placeholder="Observación" />
        <x-select wire:model.live="status" aria-label="Estado" :options="$statuses" placeholder="Estado" />
    </x-core::catalog-toolbar>

    <div class="overflow-x-auto rounded-box border border-base-300 bg-base-100">
        <x-table
            :headers="$headers"
            :rows="$page"
            :row-decoration="$rowDecoration"
            class="max-md:hidden [&_td]:px-3 [&_td]:py-2 [&_th]:px-3 [&_th]:text-[11.5px] [&_th]:font-medium [&_th]:uppercase [&_th]:tracking-[0.09em] [&_th]:text-secondary-muted [&_thead]:bg-secondary/40"
        >
            @scope('cell_code', $record)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $record->code }}</span>
            @endscope

            @scope('cell_employee_number', $record)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $record->employee_number }}</span>
            @endscope

            @scope('cell_theory_hours', $record)
                <span class="block text-end font-mono tabular-nums">{{ $record->theory_hours }}</span>
            @endscope

            @scope('cell_practice_hours', $record)
                <span class="block text-end font-mono tabular-nums">{{ $record->practice_hours }}</span>
            @endscope

            @scope('cell_credits', $record)
                <span class="block text-end font-mono tabular-nums">{{ $record->credits }}</span>
            @endscope

            @scope('cell_capacity', $record)
                <span class="block text-end font-mono tabular-nums">{{ $record->capacity }}</span>
            @endscope

            @scope('cell_building', $record)
                <span class="font-mono" title="{{ $record->building?->name }}">{{ $record->building?->code }}</span>
            @endscope

            @scope('cell_type', $record)
                {{ $record->type->label() }}
            @endscope

            @scope('cell_career', $record)
                <span class="font-mono" title="{{ $record->career?->name }}">{{ $record->career?->code }}</span>
            @endscope

            @scope('cell_flags', $record, $flagMap)
                @include('catalog-validation::review.flag-badge', ['flags' => $flagMap[$record->id] ?? []])
            @endscope

            @scope('cell_is_active', $record)
                <x-core::status-badge :tone="$record->is_active ? 'success' : 'neutral'" :label="$record->is_active ? 'Activo' : 'Inactivo'" />
            @endscope

            @scope('actions', $record, $current)
                @include('catalog-validation::review.row-actions', ['record' => $record, 'card' => $current, 'inTable' => true])
            @endscope

            <x-slot:empty>
                <x-icon name="o-clipboard-document-check" label="Ningún registro coincide con la búsqueda." />
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="record-cards">
            @foreach ($page as $record)
                <li wire:key="record-card-{{ $record->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $record->is_active])>
                    <div class="flex items-center gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $record->code ?? $record->employee_number }}</span>
                        <span @class(['min-w-0 flex-1', 'font-semibold' => $record->is_active])>{{ $record->name }}</span>
                        <x-core::status-badge :tone="$record->is_active ? 'success' : 'neutral'" :label="$record->is_active ? 'Activo' : 'Inactivo'" />
                    </div>

                    <div class="text-neutral">
                        @switch ($review->catalog)
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                                <span class="font-mono tabular-nums">{{ $record->theory_hours }}-{{ $record->practice_hours }}-{{ $record->credits }}</span>
                                @break
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                                <span class="font-mono">{{ $record->building?->code }}</span> · <span class="font-mono tabular-nums">{{ $record->capacity }}</span> lugares · {{ $record->type->label() }}
                                @break
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                                <span class="font-mono">{{ $record->career?->code }}</span>
                                @break
                        @endswitch
                    </div>

                    @if (! empty($flagMap[$record->id]))
                        @include('catalog-validation::review.flag-badge', ['flags' => $flagMap[$record->id]])
                    @endif

                    @include('catalog-validation::review.row-actions', ['record' => $record, 'card' => $current, 'inTable' => false])
                </li>
            @endforeach
        </ul>

        @if ($page->isNotEmpty() || $page->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($page->isNotEmpty())
                    <p>{{ $review->catalog->label() }} · <span class="font-mono">{{ $page->firstItem() }}–{{ $page->lastItem() }}</span> de <span class="font-mono">{{ $page->total() }}</span></p>
                @endif

                {{ $page->onEachSide(1)->links(data: ['scrollTo' => false]) }}
            </div>
        @endif
    </div>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="review-drawer-title" data-testid="review-drawer">
        @if ($editing !== null)
            @include('catalog-validation::review.drawer', ['card' => $current, 'editing' => $editing])
        @endif
    </x-drawer>
</div>
