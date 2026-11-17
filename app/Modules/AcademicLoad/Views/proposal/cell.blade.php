{{--
    Una cifra que se captura o se lee en la tabla de la propuesta (academic-load::proposal.table): los esperados o los
    grupos de una fila. Es un campo que guarda al salir (`saveCell`) si la columna es del usuario (M7) y la fila no está
    bloqueada por su estado; si no, el número como texto, no un campo deshabilitado. En la columna de un campus inactivo,
    los grupos pedidos llevan «Dejar en 0» para quien envía la propuesta (M14). `$prefix` separa los ids de la
    forma ancha (`cell`) y de la ficha (`card-cell`); `data-cell` es el mismo en las dos para marcar lo guardado.

    Con la proyección (tarea 5.6, opción C): un campo que sigue lo sugerido sin tocar lleva el borde punteado; una cifra
    corregida que se aparta del sugerido (DemandSettings::isLargeDifference, por separado en esperados y en grupos) lleva el
    borde de advertencia, `o-arrows-up-down` (el triángulo es de las anomalías), `data-large` y, para lectores de pantalla y en `title`, lo que sugiere Nexus. Solo
    resalta: no bloquea nada.

    Con paquetes de primer semestre (tarea 5.6b): los grupos son el total de la fila (`requested_groups`) y, si alguno sale de
    paquetes en ese campus, debajo dice cuántos («5 de paq.», `data-package-groups`); los demás son sueltos.
--}}
@php
    $cell = "{$item->id}.{$field}";
    $cellError = $cellErrors[$cell] ?? null;
    $readonly = isset($locks[$item->id]) || ! $column['editable'];
    $where = $column['campus']?->code;
    $suffix = count($columns) > 1 && $where !== null ? "-{$where}" : '';
    $forecastRow = $projection->row($item);
    $followsForecast = $forecastRow?->suggestion->state === \App\Modules\AcademicLoad\Enums\SuggestionState::Suggested;
    $largeDifference = $forecastRow !== null && ($field === 'expected_students' ? $forecastRow->suggestion->largeDifferenceExpected : $forecastRow->suggestion->largeDifferenceGroups);
    $forecastValue = $forecastRow === null ? null : ($field === 'expected_students' ? $forecastRow->suggestedExpected() : $forecastRow->suggestedGroups());
    $largeText = $largeDifference ? "Se aparta del sugerido: Nexus sugiere {$forecastValue}." : null;
@endphp
<div class="group/cell flex min-w-0 flex-col gap-0.5" data-cell="{{ $cell }}" @if ($largeDifference) data-large @endif>
    @if ($readonly && $field === 'requested_groups' && ($column['zeroable'] ?? false) && ! isset($locks[$item->id]) && (int) $value > 0)
        {{-- M14: la columna de un campus inactivo solo deja los grupos en cero. --}}
        <span class="flex items-center justify-end gap-1.5">
            <span class="font-mono text-sm tabular-nums" data-readonly-cell="{{ $field }}"><span class="sr-only">{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }}: </span>{{ $value }}</span>
            <button type="button" class="btn btn-ghost btn-xs h-7 min-h-7 px-1.5 text-xs max-md:h-11 max-md:min-h-11" wire:click="zeroRow('{{ $item->id }}')" wire:loading.attr="disabled" wire:target="zeroRow('{{ $item->id }}')"
                aria-label="Dejar en 0 los grupos de {{ $subjectCode }} en {{ $where }}" data-testid="{{ $prefix === 'cell' ? 'zero-row-'.$item->id : 'card-zero-row-'.$item->id }}">Dejar en 0</button>
        </span>
        @if ($cellError)
            <span class="text-xs text-error">{{ $cellError }}</span>
        @endif
    @elseif ($readonly)
        <span class="flex items-center justify-end gap-1 text-right font-mono text-sm tabular-nums" data-readonly-cell="{{ $field }}" @if ($largeText) title="{{ $largeText }}" @endif>
            @if ($largeText)
                <x-icon name="o-arrows-up-down" class="h-3.5 w-3.5 shrink-0 text-warning" aria-hidden="true" />
            @endif
            <span><span class="sr-only">{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }}: </span>{{ $value }}@if ($largeText)<span class="sr-only"> {{ $largeText }}</span>@endif</span>
        </span>
    @else
        <div class="relative">
            <input type="text" id="{{ $prefix }}-{{ $item->id }}-{{ $field }}" inputmode="numeric" pattern="[0-9]*" maxlength="{{ strlen((string) $max) }}" autocomplete="off" value="{{ $value }}"
                oninput="this.value = this.value.replace(/\D/g, '')" wire:change="saveCell('{{ $item->id }}', '{{ $field }}', $event.target.value)"
                aria-label="{{ $fieldLabel }}{{ $where ? ' en '.$where : '' }} de {{ $subjectCode }}{{ $followsForecast ? ', sugerido por Nexus' : '' }}" @if ($cellError) aria-invalid="true" @endif
                @if ($cellError || $largeText) aria-describedby="{{ trim(($cellError ? $prefix.'-error-'.$item->id.'-'.$field : '').' '.($largeText ? $prefix.'-large-'.$item->id.'-'.$field : '')) }}" @endif
                @if ($largeText) title="{{ $largeText }}" @endif
                @class([
                    'input input-sm w-full px-2 text-right font-mono text-sm tabular-nums group-data-[saved]/cell:input-success',
                    'input-error' => $cellError,
                    'input-warning border-2 ps-6' => ! $cellError && $largeText,
                    'border-dashed font-normal' => ! $cellError && ! $largeText && $followsForecast,
                ]) data-field="{{ $field }}" />
            @if ($largeText)
                <span class="pointer-events-none absolute start-1.5 top-1/2 -translate-y-1/2 text-warning group-data-[saved]/cell:hidden" aria-hidden="true"><x-icon name="o-arrows-up-down" class="block h-3.5 w-3.5" /></span>
            @endif
            {{-- En un span: el icono de MaryUI trae `inline`, que le gana a `hidden`. --}}
            <span class="pointer-events-none absolute start-1.5 top-1/2 hidden -translate-y-1/2 text-success group-data-[saved]/cell:block" aria-hidden="true"><x-icon name="o-check" class="block h-4 w-4" /></span>
        </div>
        <span class="sr-only" role="status" data-saved-status></span>
        @if ($largeText)
            <span id="{{ $prefix }}-large-{{ $item->id }}-{{ $field }}" class="sr-only">{{ $largeText }}</span>
        @endif
        @if ($cellError)
            <span id="{{ $prefix }}-error-{{ $item->id }}-{{ $field }}" class="text-xs text-error" data-testid="cell-error-{{ $subjectCode }}{{ $suffix }}-{{ $field }}">{{ $cellError }}</span>
        @endif
    @endif
    @if ($field === 'requested_groups' && $item->package_groups > 0)
        <span class="text-right font-mono text-[11.5px] leading-tight text-neutral" title="{{ $item->package_groups }} de estos grupos salen de los paquetes; los demás son sueltos." data-package-groups>{{ $item->package_groups }} de <abbr title="paquetes" class="no-underline">paq.</abbr></span>
    @endif
</div>
