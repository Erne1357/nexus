{{--
    El horario por hora (maqueta A, «Franjas por hora»): un renglón por hora de 7:00 a 21:00 (SelectionTimetableQuery::
    strips) con la hora, cuántos grupos y libres hay y «Tu hora» si tiene uno ahí; los grupos van como fichas lado a lado
    y los días como marcas en cada una. Una hora sin grupos, o sin ninguno con el filtro, es un renglón bajo. En celular
    la hora va arriba de sus fichas, en una columna. Espera $strips, $data y $inQueue.
--}}
@use('App\Modules\Scheduling\Validation\Minutes')
<section aria-label="Grupos por hora" data-testid="selection-timetable">
    <ol class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        @foreach ($strips as $strip)
            @php($empty = $strip['visible'] === [])
            <li wire:key="strip-{{ $strip['hour'] }}" data-testid="selection-strip-{{ substr($strip['hour'], 0, 2) }}"
                @class([
                    'grid border-t border-base-300 first:border-t-0',
                    'md:grid-cols-[7rem_minmax(0,1fr)]' => ! $empty,
                    'grid-cols-[5.5rem_minmax(0,1fr)] md:grid-cols-[7rem_minmax(0,1fr)]' => $empty,
                ])>
                <div @class([
                    'flex items-baseline gap-2.5 border-base-300 px-3 md:grid md:content-start md:gap-0.5 md:border-e md:px-3.5',
                    'py-2 max-md:border-b md:py-3' => ! $empty,
                    'py-2' => $empty,
                    'bg-info/10' => $strip['mine'],
                    'bg-base-200/60' => ! $strip['mine'],
                ])>
                    <p class="flex flex-wrap items-baseline gap-0.5">
                        <b @class(['font-mono leading-tight', 'text-xl' => ! $empty, 'text-[15px] text-neutral' => $empty])>{{ Minutes::labelOf($strip['hour']) }}</b>
                        @unless ($empty)
                            <span class="font-mono text-[13px] text-neutral">–{{ Minutes::labelOf($strip['until']) }}</span>
                        @endunless
                    </p>
                    @unless ($empty)
                        <p class="text-[12.5px] text-neutral">
                            <span class="font-mono font-semibold text-base-content">{{ count($strip['groups']) }}</span> {{ count($strip['groups']) === 1 ? 'grupo' : 'grupos' }} ·
                            <span class="font-mono font-semibold text-base-content">{{ $strip['free'] }}</span> {{ $strip['free'] === 1 ? 'libre' : 'libres' }}
                        </p>
                    @endunless
                    @if ($strip['mine'])
                        <p class="inline-flex items-center gap-1 text-[12.5px] font-bold text-info max-md:ms-auto"><x-icon name="o-user" class="h-3.5 w-3.5" aria-hidden="true" />Tu hora</p>
                    @endif
                </div>
                @if ($empty)
                    <p class="self-center px-3.5 py-2 text-[13px] text-neutral">{{ $strip['groups'] === [] ? 'Sin grupos a esta hora' : 'Ninguno con el filtro' }}</p>
                @else
                    <div class="grid min-w-0 gap-2 p-2 md:grid-cols-[repeat(auto-fill,minmax(15.5rem,1fr))] md:p-2.5 min-[1920px]:grid-cols-[repeat(auto-fill,minmax(16.5rem,1fr))]">
                        @foreach ($strip['visible'] as $card)
                            @include('staffing::offer.selection.card', ['card' => $card, 'days' => $data['days'], 'inQueue' => $inQueue])
                        @endforeach
                    </div>
                @endif
            </li>
        @endforeach
    </ol>
</section>
