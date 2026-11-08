{{--
    Oferta del departamento (HC-17, tarea 3.10), maqueta B «Fichas por semestre»: encabezado, pestañas por departamento
    (si tiene varios), filtros, el resumen de lo suyo, y cada semestre con una fila por materia: «tu mejor lugar» y las
    fichas de sus grupos. Al tocar una ficha, el panel de la derecha muestra el grupo y SOLO el desglose del propio
    docente. Todo viene de TeacherOfferQuery.
--}}
@php
    $position = function (array $group, bool $small = true): array {
        if ($group['is_mine']) {
            return ['info', 'Asignado a ti', 'o-check-circle'];
        }
        if ($group['rank'] === null) {
            return ['neutral', 'Sin lugar', 'o-minus-circle'];
        }

        return [$group['rank'] <= 3 ? 'success' : 'neutral', $group['rank'].' de '.$group['total'], $group['rank'] <= 3 ? 'o-star' : null];
    };
    $flagBadges = ['sin_horas' => ['warning', 'Sin horas', 'o-exclamation-triangle'], 'choque' => ['error', 'Choque', 'o-x-circle'], 'no_disponible' => ['warning', 'No disponible', 'o-clock']];
@endphp
<div class="relative space-y-4 overflow-x-clip" x-data="{ opener: null }"
    x-init="$wire.$watch('showDrawer', (open) => { if (! open) { $nextTick(() => { if (opener?.isConnected) { opener.focus(); } }); } })"
    x-on:click.capture="opener = $event.target.closest('[data-opener]') ?? opener">
    <div>
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Oferta del departamento</h1>
        <p class="mt-1.5 text-sm text-neutral" data-testid="offer-meta">
            @if ($term)
                Todos los grupos de tus departamentos, {{ $term->name }}, por semestre y materia.
            @endif
        </p>
    </div>

    @if ($teacher === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="offer-empty">
            Tu usuario no está ligado a una ficha de docente activa. Pide a tu jefe de departamento que la vincule para ver la oferta.
        </div>
    @elseif ($term === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="offer-empty">No hay un periodo activo.</div>
    @elseif ($departments === [])
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="offer-empty">
            No estás adscrito a ningún departamento. Cuando te adscriban a uno, aquí verás su oferta.
        </div>
    @else
        @if (count($departments) > 1)
            <div role="tablist" aria-label="Departamento" class="tabs tabs-border" data-testid="offer-tabs">
                @foreach ($departments as $item)
                    <button type="button" role="tab" wire:key="tab-{{ $item['code'] }}" wire:click="$set('department', '{{ $item['code'] }}')" aria-selected="{{ $item['id'] === $current['id'] ? 'true' : 'false' }}"
                        @class(['tab', 'tab-active font-semibold' => $item['id'] === $current['id']]) data-testid="offer-tab-{{ $item['code'] }}">
                        {{ $item['name'] }}
                        <span class="badge badge-neutral badge-soft ml-2 font-mono">{{ collect($offer['groups'])->where('department_id', $item['id'])->count() }}</span>
                    </button>
                @endforeach
            </div>
        @else
            <p class="text-sm font-medium" data-testid="offer-department">{{ $current['name'] }}</p>
        @endif

        <div class="flex flex-wrap items-end gap-3" role="search">
            <div class="min-w-48 flex-1">
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Materia" aria-label="Materia" clearable data-testid="offer-search" />
            </div>
            <x-select wire:model.live="semester" :options="collect($semesters)->sort()->map(fn ($n) => ['id' => (string) $n, 'name' => 'Semestre '.$n])->values()->all()" placeholder="Semestre" placeholder-value="" aria-label="Semestre" data-testid="offer-semester" />
            <div class="ml-auto flex flex-wrap gap-2">
                <x-button label="Sin docente" icon="o-user-minus" wire:click="$toggle('withoutTeacher')" aria-pressed="{{ $withoutTeacher ? 'true' : 'false' }}"
                    @class(['btn-sm', 'btn-primary' => $withoutTeacher, 'btn-outline' => ! $withoutTeacher]) data-testid="offer-filter-free" />
                <x-button label="Entre los 3 primeros" icon="o-star" wire:click="$toggle('topThree')" aria-pressed="{{ $topThree ? 'true' : 'false' }}"
                    @class(['btn-sm', 'btn-primary' => $topThree, 'btn-outline' => ! $topThree]) data-testid="offer-filter-top" />
            </div>
        </div>

        <div class="flex flex-wrap items-baseline gap-x-6 gap-y-1 text-sm text-neutral" data-testid="offer-summary">
            <span><b class="mr-1 font-mono text-xl text-base-content">{{ $summary['mine'] }}</b>asignados a ti</span>
            <span><b class="mr-1 font-mono text-xl text-base-content">{{ $summary['top'] }}</b>donde estás entre los 3 primeros</span>
            <span><b class="mr-1 font-mono text-xl text-base-content">{{ $summary['free'] }}</b>sin docente</span>
            @if (count($visible) !== count($groups))
                <span>Mostrando <span class="font-mono">{{ count($visible) }}</span> de <span class="font-mono">{{ count($groups) }}</span>
                    <x-button label="Quitar filtros" class="btn-ghost btn-xs" wire:click="clearFilters" data-testid="offer-clear" /></span>
            @endif
        </div>

        <div class="sr-only" role="status">{{ count($visible) }} grupos</div>

        @forelse (collect($visible)->groupBy('semester')->sortKeys() as $number => $inSemester)
            <section aria-label="Semestre {{ $number }}" wire:key="sem-{{ $number }}" data-testid="offer-semester-{{ $number }}">
                <div class="flex items-center gap-3 pb-2 pt-3">
                    <h2 class="font-bold">Semestre {{ $number }}</h2>
                    <span class="h-px flex-1 bg-base-300"></span>
                    <span class="font-mono text-xs text-neutral">{{ $inSemester->count() }} grupos</span>
                </div>
                <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
                    @foreach ($inSemester->groupBy('subject_code') as $subjectCode => $ofSubject)
                        @php
                            $best = $ofSubject->filter(fn ($g) => $g['rank'] !== null)->sortBy('rank')->first() ?? $ofSubject->first();
                            [$bestTone, $bestLabel, $bestIcon] = $position($best);
                        @endphp
                        <div class="grid gap-x-5 gap-y-2 border-t border-base-300 px-4 py-3.5 first:border-t-0 xl:grid-cols-[14.5rem_minmax(0,1fr)]" wire:key="subj-{{ $number }}-{{ $subjectCode }}" data-testid="offer-subject-{{ $subjectCode }}">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 xl:block">
                                <b class="block text-[15px] leading-snug">{{ $ofSubject->first()['subject_name'] }}</b>
                                <span class="font-mono text-sm text-neutral">{{ $subjectCode }}</span>
                                <div class="flex flex-wrap items-center gap-1.5 xl:mt-2">
                                    <span class="text-xs font-medium uppercase tracking-wide text-neutral">Tu mejor lugar</span>
                                    <x-core::status-badge :tone="$bestTone" :label="$bestLabel" :icon="$bestIcon" class="font-mono" data-testid="offer-best-{{ $subjectCode }}" />
                                </div>
                            </div>
                            <div class="grid gap-2 sm:grid-cols-2 min-[430px]:grid-cols-2 md:grid-cols-[repeat(auto-fill,minmax(13.25rem,1fr))]">
                                @foreach ($ofSubject as $group)
                                    @php [$tone, $label, $icon] = $position($group); @endphp
                                    <button type="button" wire:click="open('{{ $group['id'] }}')" wire:key="group-{{ $group['id'] }}" data-opener data-testid="offer-group-{{ $group['id'] }}"
                                        @class([
                                            'flex min-w-0 flex-col gap-1.5 rounded-field border border-l-4 border-base-300 bg-base-100 px-3 py-2.5 text-left hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary',
                                            'border-l-info bg-info/5' => $group['is_mine'],
                                            'border-l-success' => ! $group['is_mine'] && $group['rank'] !== null && $group['rank'] <= 3,
                                            'border-dashed border-l-solid' => $group['teacher_name'] === null && ! $group['is_mine'],
                                        ])>
                                        <span class="flex items-center gap-2">
                                            <b class="font-mono text-base">{{ $group['code'] }}</b>
                                            <span class="text-sm text-neutral">{{ $group['career'] }}</span>
                                            <x-core::status-badge :tone="$tone" :label="$label" :icon="$icon" class="ml-auto font-mono" />
                                        </span>
                                        <span class="text-sm">
                                            @forelse ($group['schedule'] as $line)
                                                <span class="whitespace-nowrap">{{ $line['days'] }} <span class="font-mono">{{ $line['time'] }}</span></span>@if (! $loop->last) · @endif
                                            @empty
                                                <span class="text-neutral">Sin horario</span>
                                            @endforelse
                                        </span>
                                        <span class="flex items-center gap-1.5 text-sm">
                                            <b class="font-mono">{{ $group['rooms'] === [] ? '—' : implode(', ', $group['rooms']) }}</b>
                                            <span class="badge badge-neutral badge-soft badge-sm font-mono" title="{{ $group['campus_name'] }}">{{ $group['campus_code'] }}</span>
                                        </span>
                                        <span class="truncate text-sm">
                                            @if ($group['is_mine'])
                                                <b>Tú</b>
                                            @elseif ($group['teacher_name'])
                                                {{ $group['teacher_name'] }}
                                            @else
                                                <span class="font-semibold text-warning">Sin docente</span>
                                            @endif
                                        </span>
                                        @if ($group['flags'] !== [] && array_intersect($group['flags'], array_keys($flagBadges)) !== [])
                                            <span class="flex flex-wrap gap-1">
                                                @foreach (array_intersect_key($flagBadges, array_flip($group['flags'])) as [$flagTone, $flagLabel, $flagIcon])
                                                    <x-core::status-badge :tone="$flagTone" :label="$flagLabel" :icon="$flagIcon" class="badge-sm" />
                                                @endforeach
                                            </span>
                                        @endif
                                    </button>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>
            </section>
        @empty
            <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center text-neutral" data-testid="offer-no-groups">
                @if (count($groups) === 0)
                    Todavía no hay grupos que ver: la oferta aparece cuando la carga de tu departamento entra a asignación de docentes.
                @else
                    Ningún grupo coincide con los filtros.
                @endif
            </div>
        @endforelse

        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-screen max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="offer-drawer-title" data-testid="offer-drawer">
            @if ($selected !== null)
                @include('staffing::offer.panel', ['group' => $selected, 'position' => $position, 'flagBadges' => $flagBadges])
            @endif
        </x-drawer>
    @endif
</div>
