{{--
    La semana en celular (menos de 768 px): los días del perfil en botones («L», «M», «Mi»…, con sus horas ocupadas;
    el elegido en `?dia=`) y la lista de horas de ese día. Cada hora lleva las sesiones que empiezan en ella; las horas
    que una sesión ya cubre no llevan renglón. Con un grupo elegido, la hora libre dice si cabe («Cabe · colocar una
    hora»), si choca o si no se puede, y tocarla coloca; sin grupo, «Libre» o la disponibilidad del docente o el
    bloqueo del salón.
--}}
@php
    $title = $selected ? $selected['subject']['code'].' · '.$selected['code'] : '';
    $dayName = collect($grid->days)->firstWhere('day', $mobileDay)['name'] ?? '';
@endphp
<div class="grid gap-2.5 md:hidden" data-testid="day-list">
    <div class="grid grid-cols-(--days) gap-1.5" style="--days: repeat({{ count($grid->days) }}, minmax(0, 1fr))" role="group" aria-label="Día">
        @foreach ($grid->days as $day)
            <button type="button" wire:click="$set('day', '{{ $day['day'] }}')" aria-pressed="{{ $day['day'] === $mobileDay ? 'true' : 'false' }}"
                aria-label="{{ $day['name'] }}, {{ $busy[$day['day']] }} h ocupadas"
                class="btn h-11 flex-col gap-0 border-base-300 px-0 leading-tight font-semibold shadow-none aria-pressed:btn-primary not-aria-pressed:bg-base-100">
                {{ $day['short'] }}
                <span class="font-mono text-[11.5px] font-normal">{{ $busy[$day['day']] }} h</span>
            </button>
        @endforeach
    </div>

    <div class="rounded-box border border-base-300 bg-base-100 px-3 pb-1">
        <h3 class="pt-2.5 pb-1 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">{{ $dayName }}</h3>
        @foreach ($mobileRows as $row)
            @php($cell = $row['cell'])
            <div class="grid grid-cols-[3rem_minmax(0,1fr)] items-start gap-2 border-t border-base-300 py-1.5 first-of-type:border-t-0" wire:key="row-{{ $mobileDay }}-{{ $row['block']['starts_at'] }}">
                <span class="pt-2.5 font-mono text-[13px] text-neutral">{{ $row['block']['label'] }}</span>
                <div class="grid min-w-0 gap-1">
                    @foreach ($row['items'] as $item)
                        <x-scheduling::editor.session :item="$item" :chain-classes="$chainClasses" mobile />
                    @endforeach

                    @if ($row['items'] === [])
                        @php($why = $cell ? implode(' ', $cell['rules']).($cell['messages'] !== [] ? ' · '.implode(' ', $cell['messages']) : '') : '')
                        @if ($cell && $cell['state'] !== 'own' && $canPlace && $cell['state'] !== 'blocked')
                            <button type="button" wire:click="place('{{ $selected['id'] }}', {{ $mobileDay }}, '{{ $row['block']['starts_at'] }}')"
                                @class(['flex h-11 w-full items-center gap-2 rounded-field px-2.5 text-left font-medium', $fills[$cell['state']]])
                                aria-label="Colocar {{ $title }} el {{ mb_strtolower($dayName) }} a las {{ $row['block']['label'] }}{{ $cell['state'] === 'clash' ? ', con choque '.$why : '' }}">
                                <x-icon :name="$cell['state'] === 'fits' ? 'o-plus' : 'o-exclamation-triangle'" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ $cell['state'] === 'fits' ? 'Cabe · colocar una hora' : 'Choca: '.$why }}</span>
                            </button>
                        @elseif ($cell && $cell['state'] !== 'own')
                            <div @class(['flex h-11 items-center gap-2 rounded-field px-2.5', $fills[$cell['state']]])>
                                <x-icon :name="match ($cell['state']) { 'fits' => 'o-plus', 'clash' => 'o-exclamation-triangle', default => 'o-no-symbol' }" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ match ($cell['state']) { 'fits' => 'Cabe', 'clash' => 'Choca: '.$why, default => 'No se puede: '.$why } }}</span>
                            </div>
                        @elseif ($row['band'])
                            <div @class(['flex h-11 items-center gap-2 rounded-field px-2.5', $row['band']['kind'] === 'preferred' ? $fills['preferred'] : $fills['blocked']])>
                                <x-icon :name="match ($row['band']['kind']) { 'preferred' => 'o-star', 'blocked' => 'o-lock-closed', default => 'o-no-symbol' }" class="h-4 w-4 shrink-0" aria-hidden="true" />
                                <span class="truncate">{{ $row['band']['label'] }}{{ ! empty($row['band']['reason']) ? ' · '.$row['band']['reason'] : '' }}</span>
                            </div>
                        @else
                            <div class="flex h-11 items-center px-1 text-neutral">Libre</div>
                        @endif
                    @endif
                </div>
            </div>
        @endforeach
    </div>
</div>
