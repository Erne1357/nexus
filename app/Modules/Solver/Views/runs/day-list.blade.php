{{--
    La semana de la comparación en celular (menos de 768 px), como la del editor (scheduling::editor.day-list): los días
    en botones («L» y cuántas sesiones cambian; el elegido en `btn-primary`, `?dia=`) y la lista de horas de ese día, cada
    hora con las sesiones que empiezan en ella (el lugar anterior dice a dónde pasa) o «Libre». Cambiar de día es del
    navegador.
--}}
<div class="grid gap-2.5 md:hidden" data-testid="run-day-list">
    <div class="grid gap-1.5" style="grid-template-columns: repeat({{ count($grid->days) }}, minmax(0, 1fr))" role="group" aria-label="Día">
        @foreach ($grid->days as $day)
            @php($changes = $dayChanges[$day['day']] ?? 0)
            <button type="button" x-on:click="day = '{{ $day['day'] }}'" aria-pressed="{{ $day['day'] === $mobileDay ? 'true' : 'false' }}"
                x-bind:aria-pressed="String(day || '{{ $firstDay }}') === '{{ $day['day'] }}' ? 'true' : 'false'"
                aria-label="{{ $day['name'] }}, {{ $changes }} {{ $changes === 1 ? 'cambio' : 'cambios' }}"
                class="btn h-12 flex-col gap-0 border-base-300 px-0 leading-tight font-semibold shadow-none aria-pressed:btn-primary not-aria-pressed:bg-base-100">
                {{ $day['short'] }}
                <span class="font-mono text-[11.5px] font-normal">{{ $changes }}</span>
            </button>
        @endforeach
    </div>

    @foreach ($grid->days as $day)
        <div class="rounded-box border border-base-300 bg-base-100 px-3 pb-1" wire:key="run-list-{{ $day['day'] }}"
            x-show="String(day || '{{ $firstDay }}') === '{{ $day['day'] }}'" @if ($day['day'] !== $mobileDay) x-cloak @endif>
            <h3 class="pt-2.5 pb-1 text-[11.5px] font-medium tracking-[0.09em] text-neutral uppercase">{{ $day['name'] }}</h3>
            @foreach ($dayRows[$day['day']] ?? [] as $dayRow)
                <div class="grid grid-cols-[3rem_minmax(0,1fr)] items-start gap-2 border-t border-base-300 py-1.5 first-of-type:border-t-0" wire:key="run-row-{{ $day['day'] }}-{{ $dayRow['block']['starts_at'] }}">
                    <span class="pt-2.5 font-mono text-[13px] text-neutral">{{ $dayRow['block']['label'] }}</span>
                    <div class="grid min-w-0 grid-cols-[minmax(0,1fr)] gap-1">
                        @forelse ($dayRow['items'] as $item)
                            <x-solver::runs.session :item="$item" :chain-classes="$chainClasses" mobile />
                        @empty
                            <div class="flex h-10 items-center px-1 text-neutral">Libre</div>
                        @endforelse
                    </div>
                </div>
            @endforeach
        </div>
    @endforeach
</div>
