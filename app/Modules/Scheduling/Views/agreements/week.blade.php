{{--
    La mini semana del salón de un préstamo (día × hora): lo ocupado en rayas con la clave del grupo, y el bloque
    pedido con borde, icono y texto («Pedido», «Enviado», «Empalma», «Aceptado», «Rechazado», «Cancelado»), porque el
    color nunca va solo. Las posiciones de la cuadrícula salen de la Query, así que van en `style`.
--}}
@php
    $week = $detail['week'];
    $first = $week['hours'][0];
    $status = $detail['status'];
    [$requestTone, $requestIcon, $requestText] = match (true) {
        $status === \App\Modules\AcademicLoad\Enums\AgreementStatus::Accepted => ['success', 'o-check-circle', 'Aceptado'],
        $status === \App\Modules\AcademicLoad\Enums\AgreementStatus::Rejected => ['error', 'o-x-circle', 'Rechazado'],
        $status === \App\Modules\AcademicLoad\Enums\AgreementStatus::Cancelled => ['neutral', 'o-minus-circle', 'Cancelado'],
        $week['clash'] !== null => ['error', 'o-x-circle', 'Empalma'],
        default => ['info', 'o-clock', $canDecide ? 'Pedido' : 'Enviado'],
    };
    $toneClasses = [
        'success' => 'border-success bg-success/10 text-success',
        'error' => 'border-error bg-error/10 text-error',
        'info' => 'border-info bg-info/10 text-info',
        'neutral' => 'border-neutral bg-base-200 text-neutral',
    ][$requestTone];
    $row = fn (string $time): int => (int) floor(\App\Modules\Scheduling\Validation\Minutes::of($time) / 60) - $first + 2;
    $rowEnd = fn (string $time): int => (int) ceil(\App\Modules\Scheduling\Validation\Minutes::of($time) / 60) - $first + 2;
    $columnOf = fn (int $day): int => array_search($day, $week['days'], true) + 2;
@endphp
<div data-testid="room-week">
    <div class="relative grid overflow-hidden rounded-box border border-base-300 bg-base-100" role="group" aria-label="Semana de {{ $detail['room'] }}"
        style="grid-template-columns: 2.125rem repeat({{ count($week['days']) }}, minmax(0, 1fr)); grid-template-rows: 1.75rem repeat({{ count($week['hours']) }}, 1.5rem)">
        <div class="bg-base-200" style="grid-column: 1; grid-row: 1"></div>
        @foreach ($week['days'] as $index => $day)
            <div class="grid place-items-center border-s border-base-300 bg-base-200 text-[11.5px] font-medium uppercase tracking-[0.06em] text-neutral" style="grid-row: 1; grid-column: {{ $index + 2 }}">{{ $week['day_labels'][$day] }}</div>
        @endforeach
        @foreach ($week['hours'] as $index => $hour)
            <div class="grid place-items-center border-t border-base-300 bg-base-200 font-mono text-[11.5px] text-neutral" style="grid-column: 1; grid-row: {{ $index + 2 }}">{{ $hour }}</div>
            @foreach ($week['days'] as $dayIndex => $day)
                <div class="border-s border-t border-base-300" style="grid-column: {{ $dayIndex + 2 }}; grid-row: {{ $index + 2 }}"></div>
            @endforeach
        @endforeach
        @foreach ($week['busy'] as $slot)
            <div class="z-[1] m-px flex min-w-0 items-start overflow-hidden rounded border border-base-300 bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_4px,var(--color-base-100)_4px_8px)] px-1.5 py-0.5 text-[11.5px] leading-tight text-neutral"
                title="{{ $slot['label'] }}" style="grid-column: {{ $columnOf($slot['day']) }}; grid-row: {{ $row($slot['starts_at']) }} / {{ $rowEnd($slot['ends_at']) }}">
                <span class="flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5"><x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><i class="max-w-full truncate font-mono not-italic">{{ $slot['label'] }}</i></span>
            </div>
        @endforeach
        <div @class(['z-[1] m-px flex min-w-0 items-start overflow-hidden rounded border-2 px-1.5 py-0.5 text-[11.5px] font-medium leading-tight', $toneClasses]) data-testid="requested-block"
            style="grid-column: {{ $columnOf($week['requested']['day']) }}; grid-row: {{ $row($week['requested']['starts_at']) }} / {{ $rowEnd($week['requested']['ends_at']) }}">
            <span class="flex min-w-0 max-w-full flex-wrap items-center gap-x-1.5"><x-icon :name="$requestIcon" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><i class="not-italic">{{ $requestText }}</i><i class="font-mono not-italic">{{ $detail['hours'] }}</i></span>
        </div>
    </div>
    <ul class="mt-2 flex flex-wrap gap-4 text-[12.5px] text-neutral">
        <li class="flex items-center gap-2"><span class="inline-block h-4 w-[22px] rounded-sm border border-base-300 bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_4px,var(--color-base-100)_4px_8px)]"></span>Ocupado</li>
        <li class="flex items-center gap-2"><span class="inline-block h-4 w-[22px] rounded-sm border-2 border-info bg-info/10"></span>{{ $status === \App\Modules\AcademicLoad\Enums\AgreementStatus::Proposed ? 'Pedido' : 'Este acuerdo' }}</li>
    </ul>
</div>
