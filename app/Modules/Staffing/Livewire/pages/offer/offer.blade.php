{{--
    Oferta del departamento (HC-17, tarea 3.10), maqueta B «Fichas por semestre»: encabezado (departamento, periodo y
    cuántos grupos tiene asignados), pestañas por departamento (si tiene varios), la barra (materia, semestre y el filtro
    segmentado «Todos · Sin docente · Entre tus 3 primeros» con sus cifras) y cada semestre con una fila por materia:
    «tu mejor lugar» y las fichas de sus grupos. Las marcas de una ficha van como icono y texto, sin insignia, para que no
    pesen más que el grupo. Al tocar una ficha, el panel de la derecha muestra el grupo y SOLO el desglose del propio
    docente. Todo viene de TeacherOfferQuery.
--}}
@use('App\Modules\Core\Enums\StatusTone')
<div class="relative space-y-4 overflow-x-clip" x-data="{ opener: null }"
    x-init="$wire.$watch('showDrawer', (open) => { if (! open) { $nextTick(() => { if (opener?.isConnected) { opener.focus(); } }); } })"
    x-on:click.capture="opener = $event.target.closest('[data-opener]') ?? opener">
    <div>
        <h1 class="text-2xl font-bold leading-tight md:text-3xl">Oferta del departamento</h1>
        @if ($term)
            <p class="mt-1.5 flex flex-wrap gap-x-4 gap-y-0.5 text-neutral" data-testid="offer-meta">
                @if (count($departments) === 1)
                    <span class="font-medium text-base-content" data-testid="offer-department">{{ $current['name'] }}</span>
                @endif
                <span>{{ $term->name }}</span>
                @if ($teacher !== null && $departments !== [])
                    <span data-testid="offer-mine"><span class="font-mono font-semibold text-base-content">{{ $mine }}</span> {{ $mine === 1 ? 'grupo asignado' : 'grupos asignados' }} a ti</span>
                @endif
            </p>
        @endif
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
            <div role="tablist" aria-label="Departamento" class="tabs tabs-border relative flex-nowrap overflow-x-auto" data-testid="offer-tabs">
                @foreach ($departments as $item)
                    <button type="button" role="tab" wire:key="tab-{{ $item['code'] }}" wire:click="$set('department', '{{ $item['code'] }}')" aria-selected="{{ $item['id'] === $current['id'] ? 'true' : 'false' }}"
                        @class(['tab shrink-0', 'tab-active font-semibold' => $item['id'] === $current['id']]) data-testid="offer-tab-{{ $item['code'] }}">
                        {{ $item['name'] }}
                        <span class="badge badge-neutral badge-soft ml-2 font-mono">{{ collect($offer['groups'])->where('department_id', $item['id'])->count() }}</span>
                    </button>
                @endforeach
            </div>
        @endif

        <div class="flex flex-wrap items-center gap-3" role="search" aria-label="Filtrar la oferta">
            <div class="w-full md:w-64">
                <x-input wire:model.live.debounce.300ms="search" icon="o-magnifying-glass" placeholder="Materia o clave" aria-label="Buscar por materia o clave" clearable data-testid="offer-search" />
            </div>
            <div class="w-full md:w-52">
                <x-select wire:model.live="semester" :options="collect($semesters)->sort()->map(fn ($n) => ['id' => (string) $n, 'name' => 'Semestre '.$n])->values()->all()" placeholder="Semestre" placeholder-value="" aria-label="Semestre" data-testid="offer-semester" />
            </div>
            <div class="min-w-0 max-md:w-full md:ms-auto">
                @include('staffing::shared.segmented-filter', [
                    'label' => 'Grupos',
                    'property' => 'state',
                    'options' => ['todos' => 'Todos', 'sin' => 'Sin docente', 'top' => 'Entre tus 3 primeros'],
                    'current' => $state,
                    'counts' => $counts,
                    'testid' => 'offer-state',
                ])
            </div>
        </div>

        @if (count($visible) !== count($groups))
            <p class="flex flex-wrap items-center gap-x-2 text-sm text-neutral" data-testid="offer-summary">
                Mostrando <span class="font-mono">{{ count($visible) }}</span> de <span class="font-mono">{{ count($groups) }}</span>
                <x-button label="Quitar filtros" class="btn-ghost btn-sm max-md:h-11" wire:click="clearFilters" data-testid="offer-clear" />
            </p>
        @endif

        <div class="sr-only" role="status">{{ count($visible) }} {{ count($visible) === 1 ? 'grupo' : 'grupos' }}</div>

        @forelse (collect($visible)->groupBy('semester')->sortKeys() as $number => $inSemester)
            <section aria-label="Semestre {{ $number }}" wire:key="sem-{{ $number }}" data-testid="offer-semester-{{ $number }}">
                <div class="flex items-center gap-3 pb-2 pt-3">
                    <h2 class="font-bold">Semestre {{ $number }}</h2>
                    <span class="h-px flex-1 bg-base-300"></span>
                    <span class="text-sm text-neutral"><span class="font-mono">{{ $inSemester->count() }}</span> {{ $inSemester->count() === 1 ? 'grupo' : 'grupos' }}</span>
                </div>
                <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
                    @foreach ($inSemester->groupBy('subject_code') as $subjectCode => $ofSubject)
                        @php($best = $ofSubject->filter(fn ($g) => $g['rank'] !== null)->sortBy('rank')->first() ?? $ofSubject->first())
                        <div class="grid gap-x-5 gap-y-2 border-t border-base-300 px-4 py-3.5 first:border-t-0 xl:grid-cols-[14.5rem_minmax(0,1fr)]" wire:key="subj-{{ $number }}-{{ $subjectCode }}" data-testid="offer-subject-{{ $subjectCode }}">
                            <div class="flex flex-wrap items-baseline gap-x-3 gap-y-1 xl:block">
                                <b class="block text-base leading-snug">{{ $ofSubject->first()['subject_name'] }}</b>
                                <span class="font-mono text-sm text-neutral">{{ $subjectCode }}</span>
                                <div class="flex flex-wrap items-center gap-1.5 xl:mt-2" data-testid="offer-best-{{ $subjectCode }}">
                                    <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Tu mejor lugar</span>
                                    @include('staffing::offer.position', ['group' => $best])
                                </div>
                            </div>
                            <div class="grid gap-2 min-[430px]:grid-cols-2 md:grid-cols-[repeat(auto-fill,minmax(13.25rem,1fr))]">
                                @foreach ($ofSubject as $group)
                                    <button type="button" wire:click="open('{{ $group['id'] }}')" wire:key="group-{{ $group['id'] }}" data-opener data-testid="offer-group-{{ $group['id'] }}"
                                        @class([
                                            'flex min-w-0 flex-col gap-1.5 rounded-field border px-3 py-2.5 text-left hover:bg-base-200 focus-visible:outline-2 focus-visible:outline-primary',
                                            'border-info/40 bg-info/5' => $group['is_mine'],
                                            'border-base-300 bg-base-100' => ! $group['is_mine'],
                                            'border-dashed' => $group['teacher_name'] === null,
                                        ])>
                                        <span class="flex items-center gap-2">
                                            <b class="font-mono text-base">{{ $group['code'] }}</b>
                                            <span class="text-sm text-neutral">{{ $group['career'] }}</span>
                                            <span class="ml-auto">@include('staffing::offer.position', ['group' => $group])</span>
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
                                        @if ($group['marks'] !== [])
                                            <span class="flex flex-wrap gap-x-3 gap-y-0.5 text-xs font-medium" data-testid="offer-marks">
                                                @foreach ($group['marks'] as $mark)
                                                    <span @class(['inline-flex items-center gap-1', 'text-error' => $mark->tone() === StatusTone::Error, 'text-warning' => $mark->tone() === StatusTone::Warning, 'text-neutral' => $mark->tone() === StatusTone::Draft])>
                                                        <x-icon :name="$mark->icon()" class="h-3.5 w-3.5" aria-hidden="true" />{{ $mark->label() }}
                                                    </span>
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
                @elseif ($state === 'top')
                    No estás entre los 3 primeros de ningún grupo que coincida, sin una marca que te baje (sin horas, choque o no disponible).
                @else
                    Ningún grupo coincide con los filtros.
                @endif
            </div>
        @endforelse

        <x-drawer wire:model.live="showDrawer" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="offer-drawer-title" data-testid="offer-drawer">
            @if ($selected !== null)
                @include('staffing::offer.panel', ['group' => $selected])
            @endif
        </x-drawer>
    @endif
</div>
