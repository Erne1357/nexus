{{--
    La lista de un catálogo (`?catalogo=`), bajo la fila de pestañas de la página, con la anatomía de las pantallas de
    catálogo (DESIGN.md, "Anatomía de una pantalla de catálogo"): un solo renglón con el nombre, el estado, las cifras
    (activos, inactivos, observaciones, altas y bajas) y Validar o Reabrir; la barra de herramientas común a la misma
    altura que en los demás catálogos (búsqueda y filtros, con «Cambio» si hay foto anterior; sin acción principal:
    Validar vive en el renglón); la tabla en una superficie con filete y, debajo de 768 px, renglones apilados que no
    repiten los data-testid. «Editar» abre el drawer (catalog-validation::review.drawer); las bajas, el suyo.
--}}
@php($review = $current['review'])
@php($catalogValue = $review->catalog->value)
@php($hasPrevious = $current['summary']['added'] !== null)
@php($isTeachers = $review->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
<div>
    <section class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-2.5" data-testid="review-block">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-bold leading-tight">{{ $review->catalog->label() }}</h2>
            <x-core::status-badge :tone="$review->status->tone()" :label="$review->status->label()" />
            @include('catalog-validation::review.who', ['review' => $review])
        </div>

        @include('catalog-validation::review.counters', ['summary' => $current['summary'], 'inline' => true, 'firstTerm' => $firstTerm])

        <div class="flex w-full gap-2 md:ms-auto md:w-auto">
            @include('catalog-validation::review.actions', ['card' => $current, 'grow' => false, 'small' => true])
        </div>

        @foreach ($current['reopenVetoes'] as $veto)
            <p class="w-full text-xs text-neutral">{{ $veto }}</p>
        @endforeach
    </section>

    <x-core::catalog-toolbar :narrow="$isTeachers && $hasPrevious">
        <x-slot:search>
            <x-input wire:model.live.debounce.300ms="search" :placeholder="$isTeachers ? 'Número o nombre' : 'Clave o nombre'" aria-label="Buscar registros" icon="o-magnifying-glass" clearable />
        </x-slot:search>

        @if ($isTeachers)
            <x-select wire:model.live="missing" aria-label="Falta" :options="$missingFilters" placeholder="Falta" />
        @endif
        @if ($hasPrevious)
            <x-select wire:model.live="change" aria-label="Cambio" :options="$changeFilters" placeholder="Cambio" data-testid="filter-change" />
        @endif
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
            @scope('cell_name', $record, $flagMap, $review)
                <span @class(['block', 'font-semibold' => $record->is_active])>{{ $record->name }}</span>
                @if ($review->catalog !== \App\Modules\CatalogValidation\Enums\Catalog::Rooms && ! empty($flagMap[$record->id]))
                    <span class="mt-1 block min-[1024px]:hidden">@include('catalog-validation::review.flag-badge', ['flags' => $flagMap[$record->id]])</span>
                @endif
            @endscope

            @scope('cell_code', $record, $flagMap, $review)
                <span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $record->code }}</span>
                @if ($review->catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms && ! empty($flagMap[$record->id]))
                    <span class="mt-1 block min-[1024px]:hidden">@include('catalog-validation::review.flag-badge', ['flags' => $flagMap[$record->id]])</span>
                @endif
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

            @scope('cell_availability', $record, $readiness)
                @if (! $record->is_active)
                    <span class="text-neutral" aria-label="Sin dato: docente inactivo">—</span>
                @elseif ($readiness[$record->id]['has_availability'] ?? false)
                    <x-core::status-badge tone="success" label="Capturada" />
                @else
                    <x-core::status-badge tone="warning" label="Falta" />
                @endif
            @endscope

            @scope('cell_profile', $record, $readiness)
                @php($subjects = $readiness[$record->id]['subjects'] ?? 0)
                <span @class(['block text-end font-mono tabular-nums', 'text-warning' => $subjects === 0 && $record->is_active])>{{ $subjects === 0 && ! $record->is_active ? '—' : $subjects }}</span>
            @endscope

            @scope('cell_external', $record, $readiness)
                <span class="block text-end font-mono tabular-nums">{{ $readiness[$record->id]['external_hours'] ?? 0 }}</span>
            @endscope

            @scope('cell_base', $record, $readiness)
                <span class="block text-end font-mono tabular-nums">{{ $readiness[$record->id]['base_hours'] ?? 0 }}</span>
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
                @include('catalog-validation::review.empty-list')
            </x-slot:empty>
        </x-table>

        <ul class="divide-y divide-base-300 md:hidden" data-testid="record-cards">
            @foreach ($page as $record)
                <li wire:key="record-card-{{ $record->id }}" @class(['space-y-1 px-4 py-3', 'text-neutral' => ! $record->is_active])>
                    <div class="flex items-center justify-between gap-2.5">
                        <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $record->code ?? $record->employee_number }}</span>
                        <x-core::status-badge :tone="$record->is_active ? 'success' : 'neutral'" :label="$record->is_active ? 'Activo' : 'Inactivo'" />
                    </div>
                    <p @class(['font-semibold' => $record->is_active])>{{ $record->name ?? '—' }}</p>

                    <div class="text-neutral">
                        @switch ($review->catalog)
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                                <span class="font-mono tabular-nums">{{ $record->theory_hours }}-{{ $record->practice_hours }}-{{ $record->credits }}</span>
                                @break
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                                <span class="font-mono">{{ $record->building?->code }}</span> · <span class="font-mono tabular-nums">{{ $record->capacity }}</span> lugares · {{ $record->type->label() }}
                                @break
                            @case (\App\Modules\CatalogValidation\Enums\Catalog::Teachers)
                                @php($ready = $readiness[$record->id] ?? null)
                                @if ($record->is_active)
                                    <x-core::status-badge :tone="($ready['has_availability'] ?? false) ? 'success' : 'warning'" :label="($ready['has_availability'] ?? false) ? 'Disponibilidad capturada' : 'Sin disponibilidad'" />
                                @else
                                    <span>Docente inactivo</span>
                                @endif
                                · <span class="font-mono tabular-nums">{{ $ready['subjects'] ?? 0 }}</span> materias en perfil · <span class="font-mono tabular-nums">{{ $ready['external_hours'] ?? 0 }}</span> h fuera
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

            @if ($page->isEmpty())
                <li class="px-4 py-6">@include('catalog-validation::review.empty-list')</li>
            @endif
        </ul>

        @if ($page->isNotEmpty() || $page->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($page->isNotEmpty())
                    @php($keyOf = fn ($record): string => $record->code ?? $record->employee_number)
                    <p>{{ $review->catalog->label() }} <span class="font-mono">{{ $keyOf($page->getCollection()->first()) }}</span>@if ($page->count() > 1) a <span class="font-mono">{{ $keyOf($page->getCollection()->last()) }}</span> · <span class="font-mono">{{ $page->firstItem() }}–{{ $page->lastItem() }}</span>@else · <span class="font-mono">{{ $page->firstItem() }}</span>@endif de <span class="font-mono">{{ $page->total() }}</span></p>
                @endif

                {{ $page->onEachSide(1)->links(data: ['scrollTo' => false, 'summary' => false]) }}
            </div>
        @endif
    </div>

    <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="review-drawer-title" data-testid="review-drawer">
        @if ($editing !== null)
            @include('catalog-validation::review.drawer', ['card' => $current, 'editing' => $editing])
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showRemoved" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="removed-drawer-title" data-testid="removed-drawer">
        @if ($showRemoved)
            @include('catalog-validation::review.removed', ['review' => $review, 'removed' => $removed])
        @endif
    </x-drawer>
</div>
