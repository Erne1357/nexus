{{--
    La línea del ciclo del re-análisis (opción B): Publicada · vN → Calificaciones → Re-análisis → Cierre → Publicar vN+1,
    con la fecha de cada paso en Mono y su detalle («84 % capturadas», «Falta la proyección»). Hechos con punto primary y
    palomita; el actual en info con anillo y `aria-current`; el siguiente ya habilitado con filete primary punteado; uno
    que espera algo que llega solo, en warning con reloj; los que siguen con filete gris. Si no cabe, se desplaza de lado
    dentro de su caja. `$steps` (ReanalysisState::steps).
--}}
<ol class="flex min-w-0 overflow-x-auto overscroll-x-contain pb-0.5 [scrollbar-width:thin]" aria-label="Ciclo del re-análisis" data-testid="reanalysis-cycle">
    @foreach ($steps as $step)
        <li class="relative grid min-w-28 content-start gap-0.5 pe-3 pt-7 md:min-w-[8.25rem] md:pe-5" @if ($step['state'] === 'current') aria-current="step" @endif data-step="{{ $step['key'] }}" data-state="{{ $step['state'] }}">
            @unless ($loop->last)
                <span @class(['absolute start-[22px] end-0 top-2.5 h-0.5', 'bg-primary' => $step['state'] === 'done', 'bg-base-300' => $step['state'] !== 'done']) aria-hidden="true"></span>
            @endunless
            <span @class([
                'absolute start-0 top-0 grid h-[22px] w-[22px] place-items-center rounded-full border-2',
                'border-primary bg-primary text-primary-content' => $step['state'] === 'done',
                'border-info bg-base-100 ring-4 ring-info/20' => $step['state'] === 'current',
                'border-dashed border-primary bg-base-100' => $step['state'] === 'ready',
                'border-warning bg-warning/15 text-warning' => $step['state'] === 'wait',
                'border-base-content/30 bg-base-100' => $step['state'] === 'next',
            ]) aria-hidden="true">
                @if ($step['state'] === 'done')
                    <x-icon name="o-check" class="h-3 w-3 stroke-[2.5]" />
                @elseif ($step['state'] === 'current')
                    <span class="h-2 w-2 rounded-full bg-info"></span>
                @elseif ($step['state'] === 'wait')
                    <x-icon name="o-clock" class="h-3 w-3" />
                @endif
            </span>
            <span @class(['whitespace-nowrap text-sm', 'font-bold' => in_array($step['state'], ['done', 'current', 'wait'], true), 'font-medium text-neutral' => in_array($step['state'], ['ready', 'next'], true)])>
                {{ $step['label'] }}
                <span class="sr-only">, {{ ['done' => 'ya pasó', 'current' => 'en curso', 'ready' => 'ya se puede', 'wait' => 'en espera', 'next' => 'pendiente'][$step['state']] }}</span>
            </span>
            <span class="text-xs leading-snug text-neutral">
                @if ($step['date'])
                    <span class="font-mono tabular-nums">{{ $step['date'] }}</span>
                @endif
                @if ($step['detail'] && (($full ?? true) || $step['key'] !== 'grades'))
                    {{ $step['date'] ? '· ' : '' }}{{ $step['detail'] }}
                @endif
            </span>
        </li>
    @endforeach
</ol>
