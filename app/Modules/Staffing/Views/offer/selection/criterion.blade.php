{{--
    Un criterio del CSV en el orden de selección: ✓ en `success` si cumple, ✗ en `error` si no, «—» sin datos; con su
    texto para lectores de pantalla («Autoevaluación: no cumple»). Espera $value (?bool), $label y $noData.
--}}
@if ($noData || $value === null)
    <span class="font-mono text-[12px] font-semibold text-neutral" aria-hidden="true">—</span><span class="sr-only">{{ $label }}: sin datos</span>
@else
    <span @class(['inline-grid h-5 w-5 flex-none place-items-center rounded-[4px] border align-middle', 'border-success/40 bg-success/10 text-success' => $value, 'border-error/40 bg-error/10 text-error' => ! $value]) title="{{ $label }}: {{ $value ? 'cumple' : 'no cumple' }}">
        <x-icon :name="$value ? 'o-check' : 'o-x-mark'" class="h-3.5 w-3.5" aria-hidden="true" /><span class="sr-only">{{ $label }}: {{ $value ? 'cumple' : 'no cumple' }}</span>
    </span>
@endif
