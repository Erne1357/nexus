{{--
    La lista de acuerdos (scheduling::pages.agreements): tres grupos plegables con su conteo; los pendientes del más
    antiguo al más reciente, sin importar el tipo. Cada acuerdo dice qué es (el salón, el día y las horas de un
    préstamo; el grupo de un grupo compartido), debajo el grupo (o los lugares de la carrera) y el otro departamento por
    su nombre: «De …» si lo decide el actor, «Para …» si lo pidió y «… → …» si solo lo ve. Los decididos llevan su
    insignia. Pliegan con Alpine, sin ir al servidor; «Decididos» empieza plegado en pantallas angostas.
--}}
@php
    $kindLoan = \App\Modules\AcademicLoad\Enums\AgreementKind::RoomLoan;
@endphp
<div class="min-h-0 flex-1 overflow-auto max-lg:overflow-visible" data-agreements-list x-data="{ open: { decide: true, sent: true, done: window.matchMedia('(min-width:1024px)').matches } }">
    @foreach ($labels as $key => $label)
        @php
            $rows = $groups[$key];
        @endphp
        <button type="button" x-on:click="open.{{ $key }} = ! open.{{ $key }}" x-bind:aria-expanded="open.{{ $key }}" data-group="{{ $key }}"
            class="flex h-9 min-h-9 w-full cursor-pointer items-center gap-2 border-y border-base-300 bg-base-200 px-3 text-start text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral first:border-t-0 lg:sticky lg:top-0 lg:z-[2]">
            <x-icon name="o-chevron-down" class="h-4 w-4 transition-transform" x-bind:class="open.{{ $key }} || '-rotate-90'" />
            {{ $label }}
            <span @class(['ms-auto rounded-full border px-2 py-px font-mono text-xs font-semibold', 'border-warning/40 bg-warning/10 text-warning' => $key === 'decide' && count($rows) > 0, 'border-base-300 bg-base-100 text-neutral' => ! ($key === 'decide' && count($rows) > 0)]) data-testid="group-count-{{ $key }}">{{ count($rows) }}</span>
        </button>
        <div x-show="open.{{ $key }}" data-testid="group-{{ $key }}">
            @forelse ($rows as $row)
                @php
                    $isLoan = $row['kind'] === $kindLoan;
                    $name = $isLoan ? "{$row['room']} · {$row['day_short']} {$row['hours']}" : ($row['group']['label'] ?? 'Grupo');
                    $other = match ($row['side'] ?? null) {
                        'to' => 'De '.$row['from']['name'],
                        'from' => 'Para '.$row['to']['name'],
                        default => $row['from']['name'].' → '.$row['to']['name'],
                    };
                @endphp
                <div wire:key="agreement-{{ $row['id'] }}"
                    class="relative flex border-b border-base-300 hover:bg-base-200 has-[[aria-current=true]]:bg-base-200 has-[[aria-current=true]]:before:absolute has-[[aria-current=true]]:before:inset-y-0 has-[[aria-current=true]]:before:start-0 has-[[aria-current=true]]:before:w-[3px] has-[[aria-current=true]]:before:bg-primary">
                    <button type="button" wire:click="pick('{{ $row['id'] }}')" aria-current="{{ $row['id'] === $selectedId ? 'true' : 'false' }}" data-agreement="{{ $row['id'] }}"
                        class="grid min-h-14 min-w-0 flex-1 cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-2.5 gap-y-0.5 py-2.5 ps-3.5 pe-3 text-start">
                        <x-icon :name="$row['kind']->icon()" class="row-span-3 mt-px h-[18px] w-[18px] text-neutral" aria-hidden="true" />
                        <span class="col-start-2 min-w-0 font-mono text-sm font-semibold break-words">{{ $name }}</span>
                        @if ($key === 'done')
                            <x-core::status-badge :tone="$row['status']->tone()" :label="$row['status']->label()" :icon="$row['status']->icon()" class="col-start-3 row-start-1 h-6 text-xs" />
                        @endif
                        <span class="col-span-2 col-start-2 flex min-w-0 flex-wrap gap-x-1.5 text-sm text-neutral">
                            @if ($isLoan)
                                <span class="font-mono">{{ $row['group']['label'] ?? 'Grupo' }}</span>
                            @else
                                <span>{{ $row['seats'] }} {{ $row['seats'] === 1 ? 'lugar' : 'lugares' }} de <span class="font-mono">{{ $row['career']['code'] }}</span></span>
                            @endif
                            <span aria-hidden="true">·</span>
                            <span class="min-w-0" title="{{ $row['from']['code'] }} → {{ $row['to']['code'] }}">{{ $other }}</span>
                        </span>
                    </button>
                </div>
            @empty
                <p class="flex items-center gap-2.5 border-b border-base-300 px-3.5 py-4 text-neutral" data-testid="group-empty-{{ $key }}">
                    @if ($key === 'decide' && ! $filtered)
                        <x-icon name="o-check-circle" class="h-[18px] w-[18px] shrink-0 text-success" aria-hidden="true" />No tienes acuerdos por decidir.
                    @else
                        Sin acuerdos aquí.
                    @endif
                </p>
            @endforelse
        </div>
    @endforeach
</div>
