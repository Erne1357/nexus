{{--
    La línea de estados de una carga (DESIGN.md, «Cierre de la carga»): Borrador, Propuesta, Asignación, Ajustes (una
    vuelta: lleva su icono), Cerrada, Aprobada si la modalidad exige el visto bueno, y Publicada, cada uno con la fecha en
    que la carga llegó a él por última vez. Los pasados en primary, el actual en info con anillo y `aria-current`, uno
    que se visitó después del actual (Ajustes antes de volver a asignación) en gris lleno, Ajustes cuando la carga no
    pasó por ahí con filete primary (es una vuelta, no un paso) y los que siguen con filete gris. Si no cabe, se
    desplaza de lado dentro de su caja.
--}}
@php($steps = $closing->steps())
<div class="relative overflow-x-auto overscroll-x-contain pb-1 [scrollbar-width:thin]">
    <ol @class(['grid min-w-[34rem]', 'grid-cols-6' => count($steps) === 6, 'grid-cols-7' => count($steps) === 7]) aria-label="Estados de la carga" data-testid="load-steps">
        @foreach ($steps as $step)
            <li class="flex min-w-0 flex-col gap-1 pe-2" @if ($step['state'] === 'current') aria-current="step" @endif data-state="{{ $step['state'] }}">
                <span class="flex items-center" aria-hidden="true">
                    <span @class([
                        'block h-3.5 w-3.5 shrink-0 rounded-full',
                        'bg-primary' => $step['state'] === 'past',
                        'bg-info ring-4 ring-info/20' => $step['state'] === 'current',
                        'bg-neutral/45' => $step['state'] === 'seen',
                        'border-2 border-base-content/30 bg-base-100' => $step['state'] === 'next',
                        'border-2 border-primary/50 bg-base-100' => $step['state'] === 'skipped',
                    ])></span>
                    @if (! $loop->last)
                        <span @class(['-me-2 h-0.5 flex-1', 'bg-primary' => in_array($step['state'], ['past', 'skipped'], true), 'bg-base-300' => ! in_array($step['state'], ['past', 'skipped'], true)])></span>
                    @endif
                </span>
                <span @class(['flex items-center gap-1 text-sm', 'font-semibold' => in_array($step['state'], ['past', 'current'], true), 'text-neutral' => in_array($step['state'], ['seen', 'next', 'skipped'], true)])>
                    @if ($step['status'] === \App\Modules\AcademicLoad\Enums\LoadStatus::Adjusting)
                        <x-icon name="o-arrows-right-left" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                    @endif
                    <span class="min-w-0 text-balance">{{ $step['label'] }}</span>
                    {{-- El punto dice el estado del paso solo a la vista; para los lectores de pantalla, en palabras. --}}
                    @if ($step['state'] !== 'current')
                        <span class="sr-only">, {{ ['past' => 'ya pasó', 'seen' => 'ya pasó', 'skipped' => 'no pasó por aquí', 'next' => 'pendiente'][$step['state']] }}</span>
                    @endif
                </span>
                <span class="h-4 font-mono text-xs text-neutral tabular-nums">{{ $step['date'] }}</span>
            </li>
        @endforeach
    </ol>
</div>
