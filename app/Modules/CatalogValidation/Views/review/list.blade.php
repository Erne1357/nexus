{{--
    La lista de un catálogo (`?catalogo=`), bajo la fila de pestañas de la página: opción A «Tabla con columna Revisión»
    (elegida por el usuario el 12 de noviembre de 2026; DESIGN.md, «Revisión por registro»).

    - El renglón de la revisión: nombre, estado, «N de M revisados» con su barra, las cifras y «Validar catálogo», que
      solo se habilita con todos los activos revisados (CV7) y, si no, lleva su motivo escrito al lado.
    - La barra: búsqueda, el filtro segmentado Por revisar · Revisados · Con observación · Todos (`?revision=`), los filtros
      del catálogo (Falta en docentes; Campus y Edificio en salones; Cambio si hay foto anterior) y, en docentes,
      «Agregar docente» (CV-S9) a la derecha. Debajo, a quien marca, los atajos de teclado.
    - La tabla: una casilla por registro sin revisar (a quien marca), la clave, el nombre con sus insignias y, en
      docentes, su correo institucional (o «Sin correo institucional» y «Agregar», que abre el recuadro del correo) y sus
      otros departamentos; las columnas del catálogo; «Revisión» («Marcar revisado» o la insignia con quién y cuándo) y
      las acciones de fila. Lo que no se puede en una fila (la baja de quien ocupa un puesto) lleva su motivo escrito en
      ella. Debajo de 768 px, renglones apilados.
    - La selección y el teclado viven en el navegador (Alpine `catalogReviewList`, resources/js/app.js): J y K mueven el
      foco por las filas, Espacio elige, R marca la fila o los elegidos y Enter abre «Acciones» (docentes) o «Editar». Con
      algo elegido, la barra «N elegidos · Elegir los M por revisar · Quitar la selección · Marcar N como revisados» se
      queda pegada al pie.
    - «Por revisar» vacío dice «Revisaste los N …» y ofrece «Validar catálogo».

    El drawer del registro (catalog-validation::review.drawer), el de bajas y el de «Agregar docente» van al final.
--}}
@php($review = $current['review'])
@php($catalog = $review->catalog)
@php($catalogValue = $catalog->value)
@php($hasPrevious = $current['summary']['added'] !== null)
@php($isTeachers = $catalog === \App\Modules\CatalogValidation\Enums\Catalog::Teachers)
@php($isRooms = $catalog === \App\Modules\CatalogValidation\Enums\Catalog::Rooms)
@php($validated = $review->status === \App\Modules\CatalogValidation\Enums\ReviewStatus::Validated)
@php($canMark = $current['canMark'])
<div
    x-data="catalogReviewList({ teachers: @js($isTeachers), domain: @js($emailDomain) })"
    x-on:keydown.window="onKey($event)"
    x-on:click="openEmailFrom($event)"
    data-pending="{{ json_encode($pendingIds) }}"
    data-can-mark="{{ $canMark ? 'true' : 'false' }}"
    wire:key="review-list-{{ $catalogValue }}-{{ $canMark ? 'marca' : 'lee' }}"
>
    <section class="mb-4 flex flex-wrap items-center gap-x-6 gap-y-2.5 rounded-box border border-base-300 bg-base-100 px-4 py-3" data-testid="review-block">
        <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
            <h2 class="text-lg font-bold leading-tight">{{ $catalog->label() }}</h2>
            <x-core::status-badge :tone="$review->status->tone()" :label="$review->status->label()" />
        </div>

        @if ($validated)
            @include('catalog-validation::review.who', ['review' => $review])
        @else
            @include('catalog-validation::review.reviewed-count', ['card' => $current])
        @endif

        @include('catalog-validation::review.counters', ['summary' => $current['summary'], 'inline' => true, 'firstTerm' => $firstTerm])

        <div class="flex w-full flex-wrap items-center justify-end gap-x-3 gap-y-2 md:ms-auto md:w-auto">
            @include('catalog-validation::review.validate-why', ['card' => $current])
            @include('catalog-validation::review.actions', ['card' => $current, 'grow' => false, 'small' => true])
        </div>

        @foreach ($current['reopenVetoes'] as $veto)
            <p class="w-full text-xs text-neutral">{{ $veto }}</p>
        @endforeach
        @if (! $canMark && $current['markReason'] !== null)
            <p id="review-locked" class="flex w-full items-center gap-1.5 text-sm text-neutral" data-testid="review-locked"><x-icon name="o-lock-closed" class="h-4 w-4 shrink-0" aria-hidden="true" />{{ $current['markReason'] }}</p>
        @endif
    </section>

    {{-- Barra de herramientas: la de catálogo (DESIGN.md), con el filtro segmentado de la revisión en lugar de «Estado». --}}
    <div class="mb-2 flex flex-wrap items-start gap-x-2.5 gap-y-3 md:items-center" data-testid="catalog-toolbar">
        <div class="w-full md:w-56" data-testid="catalog-search">
            <x-input wire:model.live.debounce.300ms="search" :placeholder="$isTeachers ? 'RFC o nombre' : 'Clave o nombre'" aria-label="Buscar registros" icon="o-magnifying-glass" clearable />
        </div>

        <div class="w-full md:w-auto">
            @include('catalog-validation::review.segments')
        </div>

        <div class="contents [&>*]:w-full md:[&>*]:w-40">
            @if ($isTeachers)
                <x-select wire:model.live="missing" aria-label="Falta" :options="$missingFilters" placeholder="Falta" data-testid="filter-missing" />
            @endif
            @if ($isRooms)
                <x-select wire:model.live="roomCampus" aria-label="Campus" :options="$roomFilters['campuses']" placeholder="Campus" data-testid="filter-campus" />
                <x-select wire:model.live="roomBuilding" aria-label="Edificio" :options="$roomFilters['buildings']" placeholder="Edificio" data-testid="filter-building" />
            @endif
            @if ($hasPrevious)
                <x-select wire:model.live="change" aria-label="Cambio" :options="$changeFilters" placeholder="Cambio" data-testid="filter-change" />
            @endif
        </div>

        @if ($isTeachers)
            <div class="w-full md:ms-auto md:w-auto">
                @if ($current['canEdit'])
                    <x-button label="Agregar docente" icon="o-user-plus" class="btn-outline w-full md:w-auto" wire:click="$set('showAddTeacher', true)" data-testid="add-teacher" />
                @else
                    <button type="button" class="btn btn-outline w-full md:w-auto" aria-disabled="true" disabled aria-describedby="add-teacher-why"><x-icon name="o-user-plus" class="h-4 w-4" aria-hidden="true" />Agregar docente</button>
                    <p id="add-teacher-why" class="mt-1 text-xs text-neutral md:max-w-56">{{ $current['editReason'] }}</p>
                @endif
            </div>
        @endif
    </div>

    @if ($canMark)
        <p class="mb-3 flex flex-wrap items-center gap-x-4 gap-y-1 text-[12.5px] text-neutral max-md:hidden" aria-label="Atajos de teclado" data-testid="keyboard-hint">
            <span class="inline-flex items-center gap-1.5"><kbd class="kbd kbd-sm font-mono">J</kbd><kbd class="kbd kbd-sm font-mono">K</kbd> moverte</span>
            <span class="inline-flex items-center gap-1.5"><kbd class="kbd kbd-sm font-mono">Espacio</kbd> elegir</span>
            <span class="inline-flex items-center gap-1.5"><kbd class="kbd kbd-sm font-mono">R</kbd> marcar revisado (o los elegidos)</span>
            <span class="inline-flex items-center gap-1.5"><kbd class="kbd kbd-sm font-mono">Enter</kbd> {{ $isTeachers ? 'acciones' : 'editar' }}</span>
        </p>
    @else
        <div class="mb-3"></div>
    @endif

    @error('selected')
        <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft mb-3 text-sm" :title="$message" data-testid="selection-error" />
    @enderror

    <section class="rounded-box border border-base-300 bg-base-100" aria-label="Registros de {{ $catalog->label() }}">
        @if ($page->isEmpty())
            @if ($allReviewed)
                <div class="flex flex-col items-center gap-3 px-5 py-10 text-center" data-testid="all-reviewed">
                    <x-icon name="o-check-circle" class="h-8 w-8 text-success" aria-hidden="true" />
                    <p class="text-xl font-bold">{{ $doneMessage }}</p>
                    @if ($validated)
                        <p class="text-neutral">El catálogo ya está validado.</p>
                    @elseif ($current['canValidate'] && $current['validateReason'] === null)
                        <p class="max-w-prose text-neutral">Ya puedes validar el catálogo. Lo que corrijas después de validar pide reabrirlo.</p>
                        <x-button label="Validar catálogo" icon="o-check" class="btn-primary" wire:click="confirm('validate', '{{ $catalogValue }}')" spinner="confirm" data-testid="validate-{{ $catalogValue }}-done" />
                    @else
                        <p class="text-neutral">Lo valida el jefe del departamento.</p>
                    @endif
                </div>
            @else
                <div class="px-4 py-6">@include('catalog-validation::review.empty-list')</div>
            @endif
        @else
            <div class="overflow-x-auto max-md:hidden">
                <table class="table [&_td]:px-3 [&_td]:py-2.5 [&_th]:px-3" data-testid="review-table">
                    <thead class="bg-secondary/40 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">
                        <tr>
                            @if ($canMark)
                                <th class="w-1">
                                    @if ($pagePending > 0)
                                        <input type="checkbox" class="checkbox checkbox-sm" aria-label="Elegir los {{ $pagePending }} por revisar de esta página" data-testid="select-page"
                                            x-bind:checked="allPageChosen()" x-on:change="choosePage($el.checked)">
                                    @endif
                                </th>
                            @endif
                            <th>{{ $isTeachers ? 'RFC' : 'Clave' }}</th>
                            <th class="min-w-40 min-[1200px]:min-w-56">{{ match ($catalog) { \App\Modules\CatalogValidation\Enums\Catalog::Teachers => 'Docente', \App\Modules\CatalogValidation\Enums\Catalog::Subjects => 'Materia', \App\Modules\CatalogValidation\Enums\Catalog::StudyPlans => 'Plan', \App\Modules\CatalogValidation\Enums\Catalog::Careers => 'Carrera', default => 'Nombre' } }}</th>
                            @switch ($catalog)
                                @case (\App\Modules\CatalogValidation\Enums\Catalog::Teachers)
                                    <th class="max-[1023px]:hidden">Disponibilidad</th>
                                    <th class="text-end max-[1199px]:hidden">Perfil</th>
                                    <th class="text-end max-[1535px]:hidden">Fuera</th>
                                    <th class="text-end max-[1535px]:hidden">Plaza</th>
                                    @break
                                @case (\App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                                    <th>Campus</th>
                                    <th class="max-[1023px]:hidden">Edificio</th>
                                    <th class="text-end max-[1023px]:hidden">Capacidad</th>
                                    <th class="max-[1535px]:hidden">Tipo</th>
                                    @break
                                @case (\App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                                    <th class="text-end">HT</th>
                                    <th class="text-end">HP</th>
                                    <th class="text-end">Créd.</th>
                                    @break
                                @case (\App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                                    <th>Carrera</th>
                                    @break
                            @endswitch
                            <th>Revisión</th>
                            <th class="w-1"><span class="sr-only">Acciones</span></th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($page as $record)
                            @php($key = $record->code ?? $record->rfc)
                            @php($isReviewed = isset($marks[$record->id]))
                            @php($isPending = $record->is_active && ! $isReviewed)
                            <tr
                                wire:key="row-{{ $record->id }}"
                                data-row="{{ $record->id }}"
                                @if ($canMark && $isPending) data-pending @endif
                                tabindex="-1"
                                aria-label="{{ $key }} · {{ $record->name }}"
                                x-bind:class="isSelected('{{ $record->id }}') && 'bg-primary/5'"
                                @class(['border-base-300 outline-primary focus-visible:outline-2 focus-visible:-outline-offset-2', 'text-neutral' => ! $record->is_active])
                            >
                                @if ($canMark)
                                    <td class="w-1">
                                        @if ($isPending)
                                            <input type="checkbox" class="checkbox checkbox-sm" aria-label="Elegir {{ $key }}" data-testid="select-{{ $key }}"
                                                x-bind:checked="isSelected('{{ $record->id }}')" x-on:change="toggle('{{ $record->id }}')">
                                        @endif
                                    </td>
                                @endif
                                <td><span @class(['whitespace-nowrap font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $isRooms ? $record->code : $key }}</span></td>
                                <td data-email-anchor="{{ $record->id }}">
                                    @include('catalog-validation::review.record-name', ['record' => $record, 'inTable' => true])
                                </td>
                                @switch ($catalog)
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Teachers)
                                        <td class="max-[1023px]:hidden">
                                            @if (! $record->is_active)
                                                <span class="text-neutral" aria-label="Sin dato: docente inactivo">—</span>
                                            @elseif ($readiness[$record->id]['has_availability'] ?? false)
                                                <x-core::status-badge tone="success" label="Capturada" />
                                            @else
                                                <x-core::status-badge tone="warning" label="Falta" />
                                            @endif
                                        </td>
                                        @php($subjects = $readiness[$record->id]['subjects'] ?? 0)
                                        <td class="text-end max-[1199px]:hidden"><span @class(['font-mono tabular-nums', 'text-warning' => $subjects === 0 && $record->is_active])>{{ $subjects === 0 && ! $record->is_active ? '—' : $subjects }}</span></td>
                                        <td class="text-end max-[1535px]:hidden"><span class="font-mono tabular-nums">{{ $readiness[$record->id]['external_hours'] ?? 0 }}</span></td>
                                        <td class="text-end max-[1535px]:hidden"><span class="font-mono tabular-nums">{{ $readiness[$record->id]['base_hours'] ?? 0 }}</span></td>
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                                        <td><span class="font-mono font-semibold" title="{{ $record->campus?->name }}">{{ $record->campus?->code }}</span></td>
                                        <td class="max-[1023px]:hidden"><span class="font-mono" title="{{ $record->building?->name }}">{{ $record->building?->code }}</span></td>
                                        <td class="text-end max-[1023px]:hidden"><span class="font-mono tabular-nums">{{ $record->capacity }}</span></td>
                                        <td class="max-[1535px]:hidden">{{ $record->type->label() }}</td>
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                                        <td class="text-end"><span class="font-mono tabular-nums">{{ $record->theory_hours }}</span></td>
                                        <td class="text-end"><span class="font-mono tabular-nums">{{ $record->practice_hours }}</span></td>
                                        <td class="text-end"><span class="font-mono tabular-nums">{{ $record->credits }}</span></td>
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                                        <td><span class="font-mono" title="{{ $record->career?->name }}">{{ $record->career?->code }}</span></td>
                                        @break
                                @endswitch
                                <td class="w-1 whitespace-nowrap">
                                    @include('catalog-validation::review.review-cell', ['record' => $record, 'inTable' => true])
                                </td>
                                <td class="w-1">
                                    @include('catalog-validation::review.row-actions', ['record' => $record, 'inTable' => true])
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            <ul class="divide-y divide-base-300 md:hidden" data-testid="record-cards">
                @foreach ($page as $record)
                    @php($key = $record->code ?? $record->rfc)
                    @php($isReviewed = isset($marks[$record->id]))
                    @php($isPending = $record->is_active && ! $isReviewed)
                    <li wire:key="record-card-{{ $record->id }}" x-bind:class="isSelected('{{ $record->id }}') && 'bg-primary/5'" @class(['grid grid-cols-[2.25rem_minmax(0,1fr)] gap-x-1 px-3 py-3', 'text-neutral' => ! $record->is_active])>
                        <div class="pt-0.5">
                            @if ($canMark && $isPending)
                                <label class="-m-2 flex h-11 w-11 cursor-pointer items-start justify-center pt-2">
                                    <input type="checkbox" class="checkbox checkbox-sm" aria-label="Elegir {{ $key }}" x-bind:checked="isSelected('{{ $record->id }}')" x-on:change="toggle('{{ $record->id }}')">
                                </label>
                            @endif
                        </div>
                        <div class="min-w-0 space-y-1">
                            <div class="flex items-start justify-between gap-2.5">
                                <span @class(['font-mono text-[15px] tabular-nums', 'font-semibold' => $record->is_active])>{{ $isRooms ? \App\Modules\CatalogValidation\Queries\CatalogContentsQuery::roomCode($record) : $key }}</span>
                                @if ($isReviewed)
                                    <x-core::status-badge tone="success" label="Revisado" />
                                @elseif ($isPending)
                                    <x-core::status-badge tone="draft" icon="o-clock" label="Por revisar" />
                                @endif
                            </div>
                            <div data-email-anchor="{{ $record->id }}">
                                @include('catalog-validation::review.record-name', ['record' => $record, 'inTable' => false])
                            </div>
                            <p class="text-[12.5px] text-neutral">
                                @switch ($catalog)
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Subjects)
                                        <span class="font-mono tabular-nums">{{ $record->theory_hours }}-{{ $record->practice_hours }}-{{ $record->credits }}</span>
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Rooms)
                                        <span class="font-mono">{{ $record->building?->code }}</span> · <span class="font-mono tabular-nums">{{ $record->capacity }}</span> lugares · {{ $record->type->label() }}
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::Teachers)
                                        @php($ready = $readiness[$record->id] ?? null)
                                        @if ($record->is_active)
                                            {{ ($ready['has_availability'] ?? false) ? 'Disponibilidad capturada' : 'Sin disponibilidad' }}
                                        @else
                                            Docente inactivo
                                        @endif
                                        · <span class="font-mono tabular-nums">{{ $ready['subjects'] ?? 0 }}</span> materias en perfil
                                        @break
                                    @case (\App\Modules\CatalogValidation\Enums\Catalog::StudyPlans)
                                        <span class="font-mono">{{ $record->career?->code }}</span> · {{ $record->career?->name }}
                                        @break
                                @endswitch
                            </p>
                            @if ($isReviewed)
                                <p class="text-[12.5px] text-neutral">Revisado por {{ $marks[$record->id]['short'] }}</p>
                            @endif
                            <div class="-ms-1 flex flex-wrap items-center gap-1 pt-1">
                                @if ($canMark && $isPending)
                                    @include('catalog-validation::review.review-cell', ['record' => $record, 'inTable' => false])
                                @endif
                                @include('catalog-validation::review.row-actions', ['record' => $record, 'inTable' => false])
                            </div>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif

        @if ($canMark)
            <div class="sticky bottom-0 z-10 flex flex-wrap items-center gap-x-4 gap-y-2 rounded-b-box border-t border-base-300 bg-base-200 px-4 py-2.5 shadow-float" role="region" aria-label="Selección" x-show="selected.length > 0" x-cloak data-testid="selection-bar">
                <span class="text-sm" aria-live="polite"><b class="font-mono font-semibold" x-text="selected.length"></b> <span x-text="selected.length === 1 ? 'elegido' : 'elegidos'"></span></span>
                <button type="button" class="link text-sm font-medium underline-offset-4" x-show="selected.length < pendingAll().length" x-on:click="chooseAllPending()" data-testid="choose-all-pending">Elegir los {{ count($pendingIds) }} por revisar</button>
                <button type="button" class="link text-sm font-medium underline-offset-4" x-on:click="clear()" data-testid="clear-selection">Quitar la selección</button>
                <button type="button" class="btn btn-primary btn-sm max-md:h-11 max-md:w-full md:ms-auto" x-on:click="markChosen()" x-bind:disabled="marking" data-testid="mark-selected">
                    <x-icon name="o-check" class="h-4 w-4" aria-hidden="true" />
                    <span x-text="'Marcar ' + selected.length + (selected.length === 1 ? ' como revisado' : ' como revisados')"></span>
                </button>
            </div>
        @endif

        @if ($page->isNotEmpty() || $page->hasPages())
            <div class="flex flex-wrap items-center justify-between gap-2 border-t border-base-300 px-4 py-2.5 text-sm text-neutral">
                @if ($page->isNotEmpty())
                    @php($keyOf = fn ($record): string => $record->code ?? $record->rfc)
                    <p>{{ $catalog->label() }} <span class="font-mono">{{ $keyOf($page->getCollection()->first()) }}</span>@if ($page->count() > 1) a <span class="font-mono">{{ $keyOf($page->getCollection()->last()) }}</span> · <span class="font-mono">{{ $page->firstItem() }}–{{ $page->lastItem() }}</span>@else · <span class="font-mono">{{ $page->firstItem() }}</span>@endif de <span class="font-mono">{{ $page->total() }}</span></p>
                @endif

                {{ $page->onEachSide(1)->links(data: ['scrollTo' => false, 'summary' => false]) }}
            </div>
        @endif
    </section>

    @if ($isTeachers)
        @include('catalog-validation::review.email-popover')
    @endif

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

    @if ($isTeachers)
        <x-drawer wire:model.live="showAddTeacher" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="add-teacher-title" data-testid="add-teacher-drawer">
            @if ($candidates !== null)
                @include('catalog-validation::review.add-teacher', ['review' => $review, 'candidates' => $candidates])
            @endif
        </x-drawer>
    @endif
</div>
