{{--
    El aviso del estado de la carga del departamento en la pantalla de solicitudes de cambio (tarea 3.11, maqueta A):
    el departamento y el campus, las etapas Propuesta · Asignación · Ajustes · Cierre con la actual marcada, la insignia
    del estado y qué pasa con las solicitudes: aprobar una pasa la carga de Asignación a Ajustes, y aplicar la última
    aprobada la regresa (reglas-de-negocio.md, "Solicitudes de cambio").
--}}
@use('App\Modules\AcademicLoad\Enums\LoadStatus')
@php
    $current = match ($load?->status) {
        null, LoadStatus::Draft => -1,
        LoadStatus::Proposed => 0,
        LoadStatus::Staffing => 1,
        LoadStatus::Adjusting => 2,
        default => 3,
    };
    $note = match ($load?->status) {
        null => 'El departamento no tiene carga en este campus.',
        LoadStatus::Staffing => 'Al aprobar una solicitud, la carga pasa a Ajustes.',
        LoadStatus::Adjusting => $approved > 0
            ? "{$approved} ".($approved === 1 ? 'aprobada por aplicar' : 'aprobadas por aplicar').' en el departamento. Al aplicarlas, la carga regresa a Asignación.'
            : 'No quedan solicitudes por aplicar; la carga sigue en Ajustes.',
        default => 'Las solicitudes se piden y se deciden con la carga en Asignación o Ajustes.',
    };
@endphp
<section class="grid items-center gap-x-8 gap-y-3 rounded-box border border-base-300 bg-base-100 px-5 py-3.5 desktop:grid-cols-[minmax(0,15rem)_minmax(0,1fr)_minmax(0,24rem)]" aria-label="Estado de la carga" data-testid="load-banner">
    <div class="min-w-0">
        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carga del departamento</span>
        <p class="font-bold leading-snug">{{ $departmentName }}</p>
        <p class="text-sm text-neutral">{{ $campusName }}{{ $termName ? ' · '.$termName : '' }}</p>
    </div>

    <ol class="flex min-w-0 items-center gap-2 max-md:hidden" aria-label="Etapas de la carga">
        @foreach (['Propuesta', 'Asignación', 'Ajustes', 'Cierre'] as $index => $stage)
            @if ($index > 0)
                <li aria-hidden="true" @class(['h-0.5 min-w-4 flex-1 rounded-full', 'bg-primary' => $index <= $current, 'bg-base-300' => $index > $current])></li>
            @endif
            <li @class(['flex shrink-0 items-center gap-1.5 text-sm', 'font-semibold text-base-content' => $index === $current, 'text-base-content' => $index < $current, 'text-neutral' => $index > $current])
                @if ($index === $current) aria-current="step" @endif>
                <span aria-hidden="true" @class([
                    'block h-2.5 w-2.5 rounded-full',
                    'bg-primary' => $index < $current,
                    'bg-info ring-4 ring-info/20' => $index === $current,
                    'border border-base-content/30' => $index > $current,
                ])></span>
                {{ $stage }}
            </li>
        @endforeach
    </ol>

    <div class="flex min-w-0 flex-wrap items-center gap-x-3 gap-y-1.5 desktop:flex-col desktop:items-start">
        @if ($load === null)
            <x-core::status-badge tone="draft" label="Sin carga" data-testid="load-status" />
        @else
            <x-core::status-badge :tone="$load->status->tone()" :label="$load->status->label()" data-testid="load-status" />
        @endif
        <span class="text-sm">{{ $note }}</span>
    </div>
</section>
