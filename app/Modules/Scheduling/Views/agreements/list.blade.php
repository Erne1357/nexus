{{--
    La lista de acuerdos (scheduling::pages.agreements): tres grupos plegables con su conteo; cada acuerdo, en dos
    renglones: el salón y las horas (o el grupo) y, debajo, el grupo (o los lugares) y de qué departamento a cuál. Los
    decididos llevan su insignia. Pliegan con Alpine, sin ir al servidor; «Decididos» empieza plegado en pantallas angostas.
--}}
<div class="min-h-0 flex-1 overflow-auto max-lg:overflow-visible" x-data="{ open: { decide: true, sent: true, done: window.matchMedia('(min-width:1024px)').matches } }">
    @foreach ($labels as $key => $label)
        @php($rows = $groups[$key])
        <button type="button" x-on:click="open.{{ $key }} = ! open.{{ $key }}" x-bind:aria-expanded="open.{{ $key }}" data-group="{{ $key }}"
            class="flex h-9 min-h-9 w-full cursor-pointer items-center gap-2 border-y border-base-300 bg-base-200 px-3 text-start text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral first:border-t-0 lg:sticky lg:top-0 lg:z-[2]">
            <x-icon name="o-chevron-down" class="h-4 w-4 transition-transform" x-bind:class="open.{{ $key }} || '-rotate-90'" />
            {{ $label }}
            <span @class(['ms-auto rounded-full border px-2 py-px font-mono text-xs font-semibold', 'border-warning/40 bg-warning/10 text-warning' => $key === 'decide' && count($rows) > 0, 'border-base-300 bg-base-100 text-neutral' => ! ($key === 'decide' && count($rows) > 0)]) data-testid="group-count-{{ $key }}">{{ count($rows) }}</span>
        </button>
        <div x-show="open.{{ $key }}" data-testid="group-{{ $key }}">
            @forelse ($rows as $row)
                <button type="button" wire:key="agreement-{{ $row['id'] }}" wire:click="pick('{{ $row['id'] }}')" aria-current="{{ $row['id'] === $selectedId ? 'true' : 'false' }}" data-agreement="{{ $row['id'] }}"
                    class="relative grid min-h-16 w-full cursor-pointer grid-cols-[auto_1fr_auto] items-center gap-x-2.5 gap-y-0.5 border-b border-base-300 py-2.5 ps-3.5 pe-3 text-start hover:bg-base-200 aria-[current=true]:bg-base-200 aria-[current=true]:before:absolute aria-[current=true]:before:inset-y-0 aria-[current=true]:before:start-0 aria-[current=true]:before:w-[3px] aria-[current=true]:before:bg-primary lg:min-h-14">
                    <x-icon :name="$row['kind']->icon()" class="row-span-2 h-[18px] w-[18px] text-neutral" aria-hidden="true" />
                    <span class="col-start-2 truncate font-mono text-sm font-semibold">
                        @if ($row['kind'] === \App\Modules\AcademicLoad\Enums\AgreementKind::RoomLoan)
                            {{ $row['room'] }} · {{ $row['day_short'] }} {{ $row['hours'] }}
                        @else
                            {{ $row['group']['label'] ?? 'Grupo' }}
                        @endif
                    </span>
                    @if ($key === 'done')
                        <x-core::status-badge :tone="$row['status']->tone()" :label="$row['status']->label()" :icon="$row['status']->icon()" class="col-start-3 row-start-1 h-6 text-xs" />
                    @endif
                    <span class="col-span-2 col-start-2 flex min-w-0 items-center gap-2 text-[12.5px] text-neutral">
                        <span class="min-w-0 truncate">
                            @if ($row['kind'] === \App\Modules\AcademicLoad\Enums\AgreementKind::RoomLoan)
                                <span class="font-mono">{{ $row['group']['label'] ?? 'Grupo' }}</span>
                            @else
                                {{ $row['seats'] }} {{ $row['seats'] === 1 ? 'lugar' : 'lugares' }} de <span class="font-mono">{{ $row['career']['code'] }}</span>
                            @endif
                        </span>
                        <span class="ms-auto shrink-0 font-mono">{{ $row['from']['code'] }} → {{ $row['to']['code'] }}</span>
                    </span>
                </button>
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
