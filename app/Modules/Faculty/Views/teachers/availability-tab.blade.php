{{--
    Pestaña Disponibilidad de la ficha del docente (DESIGN.md, "Ficha del docente" y "Cuadrícula día x hora"): el
    resumen del periodo activo en un renglón (disponible, preferido y no disponible contra las horas base), la
    cuadrícula de solo lectura con los rangos por día a su lado (debajo si la caja mide menos de 900 px), la leyenda y
    «Editar disponibilidad», que abre el editor. `$availability` sale de AvailabilityGridQuery; null sin periodo activo,
    y entonces pide elegirlo. `$editReason` es el motivo si quien ve la ficha no edita su disponibilidad (null si la
    edita). Se incluye con @include desde la ficha.
--}}
<section class="overflow-hidden rounded-box border border-base-300 bg-base-100" aria-labelledby="h-disponibilidad" data-testid="availability">
    <header class="flex flex-wrap items-center justify-between gap-2 border-b border-base-300 px-5 py-3">
        <h2 id="h-disponibilidad" class="text-lg font-bold">Disponibilidad @if ($term)<span class="ms-1.5 text-base font-normal text-neutral">{{ $term->name }}</span>@endif</h2>
        @if ($term)
            @if ($editReason === null)
                <x-button label="Editar disponibilidad" icon="o-calendar-days" class="btn-outline btn-sm" :link="route('faculty.teachers.availability', $teacher->id)" data-testid="edit-availability" />
            @else
                <span class="tooltip tooltip-left" data-tip="{{ $editReason }}">
                    <x-button label="Editar disponibilidad" icon="o-calendar-days" class="btn-outline btn-sm" disabled aria-label="Editar disponibilidad: {{ $editReason }}" data-testid="edit-availability" />
                </span>
            @endif
        @endif
    </header>

    @if ($availability === null)
        <p class="px-5 py-6 text-center text-neutral">Elige un periodo en tu credencial para ver su disponibilidad: es de un periodo.</p>
    @else
        <div class="space-y-4 p-5 max-md:p-3.5">
            <p class="flex flex-wrap gap-x-5 gap-y-1 text-neutral">
                <span>Disponible <b class="font-mono font-semibold text-base-content">{{ $availability['summary']['available'] }} h</b> de <span class="font-mono">{{ $availability['summary']['total'] }}</span></span>
                <span>Preferido <b class="font-mono font-semibold text-base-content">{{ $availability['summary']['preferred'] }} h</b></span>
                <span>No disponible <b class="font-mono font-semibold text-base-content">{{ $availability['summary']['unavailable'] }} h</b></span>
                <span>Horas base <b class="font-mono font-semibold text-base-content">{{ $availability['summary']['baseHours'] }} h</b></span>
            </p>

            <div class="@container">
                <div class="grid items-start gap-4 @min-[56.25rem]:grid-cols-[minmax(0,1fr)_20rem]">
                    <x-faculty::availability.grid :grid="$availability" />
                    <x-faculty::availability.ranges :grid="$availability" class="overflow-hidden rounded-box border border-base-300" />
                </div>
            </div>

            <x-faculty::availability.legend />
        </div>
    @endif
</section>
