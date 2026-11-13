{{--
    La lista de acuerdos (scheduling::pages.agreements): tres grupos plegables con su conteo; los pendientes del más
    antiguo al más reciente, sin importar el tipo. Cada acuerdo dice qué es (el salón, el día y las horas de un
    préstamo; el grupo de un grupo compartido), debajo el grupo (o los lugares de la carrera) y el otro departamento por
    su nombre: «De …» si lo decide el actor, «Para …» si lo pidió y «… → …» si solo lo ve. Los decididos llevan su
    insignia. Pliegan con Alpine, sin ir al servidor; «Decididos» empieza plegado en pantallas angostas.

    Quien decide elige con la casilla de cada «Por decidir» los que acepta juntos (también al tacto: la casilla ocupa
    todo el alto del renglón); un préstamo cuyo bloque empalma no se puede elegir y dice por qué. La selección vive en
    el navegador (`wire:model` sin `.live`: elegir no va al servidor) y viaja con «Aceptar N»; la cuenta, «Elegir los N…»
    y la barra al pie de la lista (pegada abajo en pantallas angostas) cambian al instante.
--}}
@php
    $kindLoan = \App\Modules\AcademicLoad\Enums\AgreementKind::RoomLoan;
    $blockedReason = fn (array $clash): string => $clash['rule'] === 'HC-11'
        ? "No se puede aceptar: el salón está bloqueado de {$clash['starts_at']} a {$clash['ends_at']} ({$clash['label']}, HC-11)."
        : "No se puede aceptar: {$clash['label']} lo ocupa de {$clash['starts_at']} a {$clash['ends_at']} (HC-02).";
@endphp
<div class="min-h-0 flex-1 overflow-auto max-lg:overflow-visible" data-agreements-list
    x-data="{
        open: { decide: true, sent: true, done: window.matchMedia('(min-width:1024px)').matches },
        choosable() { return [...$root.querySelectorAll('[data-select]:not(:disabled)')].map((box) => box.value).slice(0, {{ $maxBulk }}) },
        allChosen() { const ids = this.choosable(); return ids.length > 0 && ids.every((id) => $wire.selected.includes(id)) },
        toggleAll() { $wire.selected = this.allChosen() ? [] : this.choosable() },
    }">
    @foreach ($labels as $key => $label)
        @php
            $rows = $groups[$key];
            $choosing = $key === 'decide' && $rows !== [];
        @endphp
        <button type="button" x-on:click="open.{{ $key }} = ! open.{{ $key }}" x-bind:aria-expanded="open.{{ $key }}" data-group="{{ $key }}"
            class="flex h-9 min-h-9 w-full cursor-pointer items-center gap-2 border-y border-base-300 bg-base-200 px-3 text-start text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral first:border-t-0 lg:sticky lg:top-0 lg:z-[2]">
            <x-icon name="o-chevron-down" class="h-4 w-4 transition-transform" x-bind:class="open.{{ $key }} || '-rotate-90'" />
            {{ $label }}
            <span @class(['ms-auto rounded-full border px-2 py-px font-mono text-xs font-semibold', 'border-warning/40 bg-warning/10 text-warning' => $key === 'decide' && count($rows) > 0, 'border-base-300 bg-base-100 text-neutral' => ! ($key === 'decide' && count($rows) > 0)]) data-testid="group-count-{{ $key }}">{{ count($rows) }}</span>
        </button>
        <div x-show="open.{{ $key }}" data-testid="group-{{ $key }}">
            @if ($choosing && count($selectable) > 1)
                <div class="flex min-h-11 items-center border-b border-base-300 ps-1 pe-3">
                    <button type="button" x-on:click="toggleAll()" class="btn btn-ghost btn-sm max-md:h-11 max-md:min-h-11" data-testid="agreements-select-all">
                        <x-icon name="o-check-circle" class="h-4 w-4" x-show="! allChosen()" />
                        <x-icon name="o-minus-circle" class="h-4 w-4" x-show="allChosen()" x-cloak />
                        @php
                            $chooseLabel = count($selectable) > $maxBulk ? "Elegir los primeros {$maxBulk} de ".count($selectable).' que puedes aceptar' : 'Elegir los '.count($selectable).' que puedes aceptar';
                        @endphp
                        <span x-text="allChosen() ? 'Quitar la selección' : @js($chooseLabel)">{{ $chooseLabel }}</span>
                    </button>
                    @if (count($selectable) > $maxBulk)
                        <span class="ms-auto text-sm text-neutral">Se aceptan hasta {{ $maxBulk }} a la vez.</span>
                    @endif
                </div>
            @endif
            @forelse ($rows as $row)
                @php
                    $isLoan = $row['kind'] === $kindLoan;
                    $clash = $choosing ? ($blocked[$row['id']] ?? null) : null;
                    $name = $isLoan ? "{$row['room']} · {$row['day_short']} {$row['hours']}" : ($row['group']['label'] ?? 'Grupo');
                    $other = match ($row['side'] ?? null) {
                        'to' => 'De '.$row['from']['name'],
                        'from' => 'Para '.$row['to']['name'],
                        default => $row['from']['name'].' → '.$row['to']['name'],
                    };
                @endphp
                <div wire:key="agreement-{{ $row['id'] }}"
                    class="relative flex border-b border-base-300 hover:bg-base-200 has-[[aria-current=true]]:bg-base-200 has-[[aria-current=true]]:before:absolute has-[[aria-current=true]]:before:inset-y-0 has-[[aria-current=true]]:before:start-0 has-[[aria-current=true]]:before:w-[3px] has-[[aria-current=true]]:before:bg-primary">
                    @if ($choosing)
                        <label @class(['flex w-11 shrink-0 justify-center pt-3', 'cursor-pointer' => $clash === null, 'cursor-not-allowed' => $clash !== null])>
                            <input type="checkbox" class="checkbox checkbox-sm" value="{{ $row['id'] }}" wire:model="selected" aria-label="Elegir {{ $name }} para aceptar"
                                @if ($clash !== null) aria-describedby="blocked-{{ $row['id'] }}" @endif data-select="{{ $row['id'] }}" @disabled($clash !== null)>
                        </label>
                    @endif
                    <button type="button" wire:click="pick('{{ $row['id'] }}')" aria-current="{{ $row['id'] === $selectedId ? 'true' : 'false' }}" data-agreement="{{ $row['id'] }}"
                        @class(['grid min-h-14 min-w-0 flex-1 cursor-pointer grid-cols-[auto_minmax(0,1fr)_auto] items-start gap-x-2.5 gap-y-0.5 py-2.5 pe-3 text-start', 'ps-3.5' => ! $choosing])>
                        <x-icon :name="$row['kind']->icon()" class="row-span-3 mt-px h-[18px] w-[18px] text-neutral" aria-hidden="true" />
                        <span class="col-start-2 flex min-w-0 flex-wrap items-center gap-x-1.5 gap-y-0.5">
                            <span class="min-w-0 font-mono text-sm font-semibold break-words">{{ $name }}</span>
                            @if ($campusOptions->count() > 1)
                                <x-core::campus-tag :campus="$row['campus'] === null ? null : (object) $row['campus']" data-testid="agreement-campus" />
                            @endif
                        </span>
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
                        @if ($clash !== null)
                            <span id="blocked-{{ $row['id'] }}" class="col-span-2 col-start-2 flex items-start gap-1.5 text-sm text-error"><x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" />{{ $blockedReason($clash) }}</span>
                        @endif
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
@if ($groups['decide'] !== [])
    <div class="flex flex-wrap items-center gap-2 border-t border-base-300 bg-base-100 px-3 py-2.5 max-lg:sticky max-lg:bottom-0 max-lg:z-10" data-testid="bulk-bar"
        x-data="{
            confirmAccept() {
                const n = $wire.selected.length
                $dispatch('nexus-confirm', {
                    title: `¿Aceptar ${n} ${n === 1 ? 'acuerdo' : 'acuerdos'}?`,
                    message: 'Cada uno se acepta por separado, con su bitácora y su aviso; si alguno no se puede, los demás sí se aceptan.',
                    button: 'Aceptar',
                    tone: 'primary',
                    run: () => $wire.acceptMany(),
                })
            },
        }"
        x-show="$wire.selected.length > 0" @if ($selected === []) x-cloak @endif>
        <span class="me-auto text-sm"><span class="font-mono font-semibold" x-text="$wire.selected.length">{{ count($selected) }}</span> <span x-text="$wire.selected.length === 1 ? 'elegido' : 'elegidos'">{{ count($selected) === 1 ? 'elegido' : 'elegidos' }}</span></span>
        <button type="button" class="btn btn-ghost max-md:h-11 max-md:min-h-11" x-on:click="$wire.selected = []" data-testid="bulk-clear">Quitar</button>
        <button type="button" class="btn btn-primary max-md:h-11 max-md:min-h-11" x-on:click="confirmAccept()" x-bind:disabled="$wire.selected.length > {{ $maxBulk }}"
            aria-describedby="bulk-limit" wire:loading.attr="disabled" wire:target="acceptMany" data-testid="bulk-accept">
            <span class="loading loading-spinner loading-sm" wire:loading wire:target="acceptMany"></span>
            <x-icon name="o-check" class="h-4 w-4" wire:loading.remove wire:target="acceptMany" />
            <span x-text="'Aceptar ' + $wire.selected.length">Aceptar {{ count($selected) }}</span>
        </button>
        <p id="bulk-limit" class="basis-full text-sm text-error" role="alert" x-show="$wire.selected.length > {{ $maxBulk }}" x-cloak>Se aceptan hasta {{ $maxBulk }} a la vez: quita algunos de la selección.</p>
        @error('selected')
            <p class="basis-full text-sm text-error" role="alert" data-testid="bulk-error">{{ $message }}</p>
        @enderror
    </div>
@endif
