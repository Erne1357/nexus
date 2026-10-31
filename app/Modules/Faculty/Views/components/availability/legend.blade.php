{{--
    Leyenda de la cuadrícula de disponibilidad: disponible, no disponible y preferido, cada uno con su muestra.
--}}
<p {{ $attributes->class('flex flex-wrap items-center gap-x-[18px] gap-y-2 text-[13px] text-neutral') }}>
    <span class="inline-flex items-center gap-[7px]"><x-faculty::availability.swatch />Disponible</span>
    @foreach (\App\Modules\Faculty\Enums\AvailabilityKind::cases() as $kind)
        <span class="inline-flex items-center gap-[7px]"><x-faculty::availability.swatch :kind="$kind" />{{ $kind->label() }}</span>
    @endforeach
</p>
