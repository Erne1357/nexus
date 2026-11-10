{{--
    La mini semana del detalle (día × hora). En un préstamo, la del salón: lo ocupado en rayas con la clave del grupo y el
    bloque pedido con borde, icono y texto («Pedido», «Enviado», «Empalma», «Aceptado», «Rechazado», «Cancelado»),
    porque el color nunca va solo. En un grupo compartido, la del grupo: sus sesiones con sus horas y su salón (o «Sin
    salón»), con el anillo `primary` del grupo elegido del editor.

    La cuadrícula va con filetes sobre la superficie del panel, sin caja propia. Si mide menos de 36rem (consulta de
    contenedor), los bloques solo llevan su icono y debajo va la lista con el texto completo: en un préstamo, lo del día
    pedido; en un grupo, todas sus sesiones. Las posiciones salen de la Query, así que van en `style`.
--}}
@php
    $statuses = \App\Modules\AcademicLoad\Enums\AgreementStatus::class;
    $minutes = \App\Modules\Scheduling\Validation\Minutes::class;
    $isLoanWeek = $detail['week'] !== null;
    $week = $isLoanWeek ? $detail['week'] : $detail['schedule'];
    $first = $week['hours'][0];
    $status = $detail['status'];
    $row = fn (string $time): int => (int) floor($minutes::of($time) / 60) - $first + 2;
    $rowEnd = fn (string $time): int => (int) ceil($minutes::of($time) / 60) - $first + 2;
    $columnOf = fn (int $day): int => array_search($day, $week['days'], true) + 2;
    $short = fn (string $time): string => ltrim($time, '0');
    $stripes = 'bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-neutral)_9%,var(--color-base-100))_0_4px,var(--color-base-100)_4px_8px)]';

    if ($isLoanWeek) {
        [$requestTone, $requestIcon, $requestText] = match (true) {
            $status === $statuses::Accepted => ['success', 'o-check-circle', 'Aceptado'],
            $status === $statuses::Rejected => ['error', 'o-x-circle', 'Rechazado'],
            $status === $statuses::Cancelled => ['neutral', 'o-minus-circle', 'Cancelado'],
            $week['clash'] !== null => ['error', 'o-x-circle', 'Empalma'],
            default => ['info', 'o-clock', $canDecide ? 'Pedido' : 'Enviado'],
        };
        $marks = [[
            ...$week['requested'], 'icon' => $requestIcon, 'text' => $requestText, 'detail' => $detail['hours'], 'testid' => 'requested-block',
            'classes' => ['success' => 'border-2 border-success bg-success/10 text-success', 'error' => 'border-2 border-error bg-error/10 text-error', 'info' => 'border-2 border-info bg-info/10 text-info', 'neutral' => 'border-2 border-neutral bg-base-200 text-neutral'][$requestTone],
        ]];
        $busy = $week['busy'];
        $listTitle = 'El '.$minutes::dayName($week['requested']['day']).' en '.$detail['room'];
        $list = collect($busy)->where('day', $week['requested']['day'])
            ->map(fn (array $slot): array => ['starts_at' => $slot['starts_at'], 'hours' => $short($slot['starts_at']).'–'.$short($slot['ends_at']), 'icon' => 'o-lock-closed', 'color' => 'text-neutral', 'text' => $slot['label']])
            ->push(['starts_at' => $week['requested']['starts_at'], 'hours' => $detail['hours'], 'icon' => $requestIcon, 'color' => 'text-'.$requestTone, 'text' => $requestText])
            ->sortBy('starts_at')->values()->all();
        $ariaLabel = 'Semana de '.$detail['room'];
        $testid = 'room-week';
    } else {
        $marks = array_map(fn (array $session): array => [
            ...$session, 'icon' => $session['room'] === null ? 'o-exclamation-triangle' : 'o-map-pin', 'text' => $session['room'] ?? 'Sin salón', 'detail' => $session['hours'], 'testid' => 'group-session',
            'classes' => 'border-2 border-primary bg-primary/10 text-base-content',
        ], $week['sessions']);
        $busy = [];
        $listTitle = 'Sesiones de '.($detail['group']['label'] ?? 'el grupo');
        $list = array_map(fn (array $session): array => [
            'hours' => $minutes::dayLabel($session['day'], short: true).' '.$session['hours'], 'icon' => $session['room'] === null ? 'o-exclamation-triangle' : 'o-map-pin',
            'color' => $session['room'] === null ? 'text-warning' : 'text-neutral', 'text' => $session['room'] ?? 'Sin salón',
        ], $week['sessions']);
        $ariaLabel = 'Horario de '.($detail['group']['label'] ?? 'el grupo');
        $testid = 'group-week';
    }
@endphp
<div class="@container" data-testid="{{ $testid }}">
    <div class="relative grid overflow-hidden border border-base-300" role="group" aria-label="{{ $ariaLabel }}"
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
        @foreach ($busy as $slot)
            <div class="z-[1] m-px flex min-w-0 items-start overflow-hidden rounded border border-base-300 {{ $stripes }} px-1.5 py-0.5 text-[11.5px] leading-tight text-neutral @max-xl:justify-center @max-xl:px-0"
                title="{{ $slot['label'] }} · {{ $short($slot['starts_at']) }}–{{ $short($slot['ends_at']) }}" style="grid-column: {{ $columnOf($slot['day']) }}; grid-row: {{ $row($slot['starts_at']) }} / {{ $rowEnd($slot['ends_at']) }}">
                <span class="flex min-w-0 max-w-full items-center gap-x-1.5"><x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" /><i class="truncate font-mono not-italic @max-xl:sr-only">{{ $slot['label'] }}</i></span>
            </div>
        @endforeach
        @foreach ($marks as $mark)
            <div @class(['z-[1] m-px flex min-w-0 items-start overflow-hidden rounded px-1.5 py-0.5 text-[11.5px] font-medium leading-tight @max-xl:justify-center @max-xl:px-0', $mark['classes']]) data-testid="{{ $mark['testid'] }}"
                title="{{ $mark['text'] }} · {{ $mark['detail'] }}" style="grid-column: {{ $columnOf($mark['day']) }}; grid-row: {{ $row($mark['starts_at']) }} / {{ $rowEnd($mark['ends_at']) }}">
                <span class="flex min-w-0 max-w-full items-start gap-x-1.5">
                    <x-icon :name="$mark['icon']" class="mt-px h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    <span class="flex min-w-0 flex-wrap gap-x-1.5 @max-xl:sr-only"><i class="truncate not-italic">{{ $mark['text'] }}</i><i class="font-mono not-italic">{{ $mark['detail'] }}</i></span>
                </span>
            </div>
        @endforeach
    </div>

    <div class="@xl:hidden mt-3" data-testid="{{ $testid }}-list">
        <p class="text-sm font-bold">{{ $listTitle }}</p>
        <ul class="mt-1 divide-y divide-base-300 border-y border-base-300 text-sm">
            @foreach ($list as $item)
                <li class="flex min-h-9 items-center gap-2.5 py-1.5"><x-icon :name="$item['icon']" @class(['h-4 w-4 shrink-0', $item['color']]) aria-hidden="true" /><span class="min-w-[7.5rem] shrink-0 whitespace-nowrap font-mono">{{ $item['hours'] }}</span><span class="min-w-0 break-words">{{ $item['text'] }}</span></li>
            @endforeach
        </ul>
    </div>

    @if ($isLoanWeek)
        <ul class="mt-2 flex flex-wrap gap-4 text-sm text-neutral">
            <li class="flex items-center gap-2"><span class="inline-block h-4 w-[22px] rounded-sm border border-base-300 {{ $stripes }}"></span>Ocupado</li>
            <li class="flex items-center gap-2"><span class="inline-block h-4 w-[22px] rounded-sm border-2 border-info bg-info/10"></span>{{ $status === $statuses::Proposed ? 'Pedido' : 'Este acuerdo' }}</li>
        </ul>
    @endif
</div>
