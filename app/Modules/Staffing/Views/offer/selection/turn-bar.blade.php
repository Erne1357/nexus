{{--
    La barra del turno, pegada arriba (maqueta B, «turnbar»): qué momento es, la cuenta de horas y las acciones
    («Orden de selección» siempre; «Terminar mi carga» en su turno). Según `$bar['kind']`:
    - mine: «Es tu turno · hasta las 11:00» con la cuenta regresiva (Alpine, desde `ends_at` del servidor y con el
      desfase del reloj del navegador; el refresco de cada 5 s la vuelve a poner en su lugar);
    - other: quién está en turno y hasta cuándo, «Solo consulta hasta tu turno» y «Tu turno: martes 8 · 10:30–11:00»;
    - published: cuándo empieza la selección y su turno;
    - idle: nadie en turno ahora y quién sigue;
    - done: ya terminó su carga; extra: cerró con menos y vuelve en la ronda extra;
    - manager: la jefatura, con quién está en turno, cuántos terminaron y «Asignas cualquier grupo, a cualquier hora.».
    Espera $bar, $data, $selection y $assigns.
--}}
@php
    $kind = $bar['kind'];
    $mineSlot = $bar['mine_day'] === null ? null : $bar['mine_day'].' · '.$bar['mine_time'];
@endphp
<div role="region" aria-label="Turno de la selección" data-testid="selection-turn-bar"
    @class([
        'sticky top-[calc(var(--shell-top)+0.625rem)] z-10 flex flex-wrap items-center gap-x-6 gap-y-3 rounded-box border px-4 py-2.5 shadow-sm max-md:top-[calc(var(--shell-top)+0.375rem)] max-md:gap-x-3 max-md:px-2.5 max-md:py-2',
        'border-info/40 bg-[color-mix(in_oklab,var(--color-info)_7%,var(--color-base-100))]' => $kind === 'mine',
        'border-base-300 bg-base-100' => $kind !== 'mine',
    ])>
    <div @class(['flex min-w-0 items-center gap-3', 'max-md:flex-1' => $kind === 'mine'])>
        @if ($kind === 'mine')
            <span class="relative flex h-3 w-3 flex-none" aria-hidden="true">
                <span class="absolute inline-flex h-full w-full rounded-full bg-info opacity-40 motion-safe:animate-ping"></span>
                <span class="relative inline-flex h-3 w-3 rounded-full bg-info"></span>
            </span>
            <div>
                <p class="leading-tight"><b>Es tu turno</b> <span class="text-neutral">· hasta las {{ $bar['until'] }}</span></p>
                <p class="flex items-baseline gap-1.5">
                    <span class="font-mono text-[26px] font-semibold leading-tight tabular-nums" role="timer" aria-live="off" data-testid="selection-countdown" data-ends-at="{{ $bar['ends_at'] }}" wire:key="countdown-{{ $bar['ends_at'] }}"
                        x-data="{ ends: {{ (int) $bar['ends_ms'] }}, skew: {{ (int) $bar['now_ms'] }} - Date.now(), left: @js($bar['left']), timer: null, init() { this.tick(); this.timer = setInterval(() => this.tick(), 1000) }, destroy() { clearInterval(this.timer) }, tick() { const s = Math.max(0, Math.ceil((this.ends - Date.now() - this.skew) / 1000)); this.left = Math.floor(s / 60) + ':' + String(s % 60).padStart(2, '0') } }"
                        x-text="left">{{ $bar['left'] }}</span>
                    <span class="text-sm text-neutral max-md:hidden">para terminar</span>
                </p>
            </div>
        @else
            @php($icon = match ($kind) { 'other' => 'o-eye', 'published' => 'o-calendar-days', 'done' => 'o-check-circle', 'extra' => 'o-arrow-path', default => 'o-clock' })
            <x-icon :name="$icon" :class="'h-5.5 w-5.5 flex-none '.match ($kind) { 'done' => 'text-success', 'published' => 'text-neutral', default => 'text-info' }" aria-hidden="true" />
            <div class="min-w-0">
                @switch ($kind)
                    @case ('other')
                        <p class="leading-tight"><b>En turno: {{ $bar['current'] }}</b> <span class="text-neutral">· hasta las {{ $bar['until'] }}</span></p>
                        <p class="text-sm text-neutral max-md:hidden">Solo consulta hasta tu turno.</p>
                        @break
                    @case ('published')
                        <p class="leading-tight"><b>La selección empieza el {{ $bar['starts']?->isoFormat('dddd D [de] MMMM') }} a las {{ $bar['starts']?->format('G:i') }}</b></p>
                        <p class="text-sm text-neutral max-md:hidden">
                            @if ($bar['published'])
                                El orden se publicó el {{ $bar['published']->isoFormat('dddd D') }}. Mientras tanto, revisa la oferta.
                            @else
                                Mientras tanto, revisa la oferta.
                            @endif
                        </p>
                        @break
                    @case ('done')
                        <p class="leading-tight"><b>Terminaste tu carga</b></p>
                        <p class="text-sm text-neutral max-md:hidden">Tus grupos quedan a tu nombre; solo tu jefe de departamento o el JPD la cambian.</p>
                        @break
                    @case ('extra')
                        <p class="leading-tight"><b>Vuelves a elegir en la ronda extra</b></p>
                        <p class="text-sm text-neutral max-md:hidden">En el mismo orden, cuando termine la primera ronda.</p>
                        @break
                    @case ('manager')
                        @if ($bar['current'] !== null)
                            <p class="leading-tight"><b>En turno: {{ $bar['current'] }}</b></p>
                            <p class="text-sm text-neutral">Lugar {{ $bar['current_position'] }} de {{ $bar['total'] }} · hasta las {{ $bar['until'] }} · terminaron {{ $bar['done'] }}</p>
                        @elseif ($selection->status === \App\Modules\Staffing\Enums\SelectionStatus::Published)
                            <p class="leading-tight"><b>La selección empieza el {{ $bar['starts']?->isoFormat('dddd D [de] MMMM') }} a las {{ $bar['starts']?->format('G:i') }}</b></p>
                            <p class="text-sm text-neutral">{{ $bar['total'] }} docentes en la cola.</p>
                        @else
                            <p class="leading-tight"><b>Nadie en turno ahora</b></p>
                            <p class="text-sm text-neutral">
                                @if ($bar['next'])
                                    Sigue {{ $bar['next']['name'] }} el {{ $bar['next']['at']->isoFormat('dddd D') }} a las {{ $bar['next']['at']->format('G:i') }} ·
                                @endif
                                terminaron {{ $bar['done'] }} de {{ $bar['total'] }}
                            </p>
                        @endif
                        @break
                    @default
                        <p class="leading-tight"><b>Nadie en turno ahora</b></p>
                        @if ($bar['next'])
                            <p class="text-sm text-neutral">Sigue {{ $bar['next']['name'] }} el {{ $bar['next']['at']->isoFormat('dddd D') }} a las {{ $bar['next']['at']->format('G:i') }}.</p>
                        @endif
                @endswitch
            </div>
        @endif
    </div>

    @if ($kind === 'manager')
        @if ($assigns)
            <p class="flex items-center gap-1.5 text-sm text-neutral max-desktop:hidden"><x-icon name="o-user-plus" class="h-4 w-4" aria-hidden="true" />Asignas cualquier grupo, a cualquier hora.</p>
        @endif
    @else
        @if (in_array($kind, ['other', 'published', 'idle', 'extra'], true) && $mineSlot !== null)
            <div class="grid leading-tight md:border-s md:border-base-300 md:ps-5" data-testid="selection-my-slot">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Tu turno</span>
                <b>{{ $mineSlot }}</b>
                <span class="text-[13px] text-neutral">{{ $bar['mine_in'] ? $bar['mine_in'].' · ' : '' }}lugar {{ $bar['position'] }} de {{ $bar['total'] }}</span>
            </div>
        @endif
        @include('staffing::offer.selection.meter', ['hours' => $data['my_hours'], 'base' => $data['base_hours'], 'compact' => $kind !== 'mine'])
    @endif

    <div class="ms-auto flex items-center gap-2 max-md:ms-0 max-md:w-full">
        <x-button label="Orden de selección" icon="o-queue-list" :class="'whitespace-nowrap max-md:h-11 max-md:flex-1 max-md:px-2 max-md:text-[13px]'.($kind === 'mine' ? ' btn-ghost' : '')" wire:click="$set('showOrder', true)" data-testid="selection-order-open" />
        @if ($kind === 'mine')
            <x-button label="Terminar mi carga" icon="o-check" class="btn-primary whitespace-nowrap max-md:h-11 max-md:flex-1 max-md:px-2 max-md:text-[13px]" wire:click="$set('showFinish', true)" data-testid="selection-finish" />
        @endif
    </div>
</div>
