{{--
    Los rangos de disponibilidad por día (DESIGN.md, "Cuadrícula día x hora"): el día con sus horas disponibles y,
    a la derecha, cada rango con su icono, su tipo y sus horas en Mono; un día sin rangos dice «Disponible todo el
    día». En la ficha salen del servidor (`$grid['ranges']`); en el editor (`$interactive`) los recalcula el componente
    Alpine `availabilityEditor` mientras se pinta. Debajo de 768 px el día va arriba de sus rangos.
--}}
@props(['grid', 'interactive' => false])

@php
    use App\Modules\Faculty\Enums\AvailabilityKind;
@endphp

<div {{ $attributes->class('grid') }} data-testid="availability-ranges">
    @foreach ($grid['days'] as $d => $day)
        <div class="grid grid-cols-[5.75rem_minmax(0,1fr)] items-baseline gap-x-3 gap-y-1 border-t border-base-300 px-4 py-2.5 first:border-t-0 max-md:grid-cols-1 max-md:px-3.5">
            <span class="font-semibold">
                {{ $day['name'] }}
                <small class="block text-[12.5px] font-normal text-neutral"><b class="font-mono font-semibold text-base-content" @if ($interactive) x-text="hours(dayAvailable[{{ $d }}])" @endif>{{ $day['available'] }}</b> h disp.</small>
            </span>
            <span class="flex min-w-0 flex-wrap gap-x-3.5 gap-y-1">
                @if ($interactive)
                    <template x-for="range in ranges[{{ $d }}]" :key="range.key">
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-[13.5px]">
                            <span x-show="range.kind === '{{ AvailabilityKind::Unavailable->value }}'" class="inline-flex text-secondary-muted"><x-icon :name="AvailabilityKind::Unavailable->icon()" class="h-[15px] w-[15px]" /></span>
                            <span x-show="range.kind === '{{ AvailabilityKind::Preferred->value }}'" class="inline-flex text-success"><x-icon :name="AvailabilityKind::Preferred->icon()" class="h-[15px] w-[15px]" /></span>
                            <span x-text="range.label"></span>
                            <b class="font-mono text-[13px] font-semibold" x-text="range.time"></b>
                        </span>
                    </template>
                    <span x-show="ranges[{{ $d }}].length === 0" class="text-neutral">Disponible todo el día</span>
                @else
                    @forelse ($grid['ranges'][$d] as $range)
                        <span class="inline-flex items-center gap-1.5 whitespace-nowrap text-[13.5px]">
                            <span @class(['inline-flex', 'text-secondary-muted' => $range['kind'] === AvailabilityKind::Unavailable, 'text-success' => $range['kind'] === AvailabilityKind::Preferred])><x-icon :name="$range['kind']->icon()" class="h-[15px] w-[15px]" /></span>
                            {{ $range['kind']->label() }}
                            <b class="font-mono text-[13px] font-semibold">{{ $range['label'] }}</b>
                        </span>
                    @empty
                        <span class="text-neutral">Disponible todo el día</span>
                    @endforelse
                @endif
            </span>
        </div>
    @endforeach
</div>
