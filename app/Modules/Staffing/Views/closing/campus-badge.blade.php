{{--
    La insignia de una carga en «Avance del cierre» (maqueta E): el código del campus con el icono de su cubeta y la
    cifra que importa («C2 · 21» con faltantes, en grupos distintos, en error si alguno es choque duro; sin cifra si solo
    faltan trámites; «C1» con `o-check` lista para cerrar;
    con `o-lock-closed` cerrada; «C1 · v2» publicada). Es un botón que abre su detalle; el nombre del campus y el estado
    van en `aria-label` y `title`. `$progress` (LoadProgress) y `$selected` (la del drawer abierto).
--}}
@use('App\Modules\Staffing\Queries\LoadClosing')
@php
    $load = $progress->load;
    $missing = $progress->missing();
    [$tone, $icon, $extra, $state] = match ($progress->bucket()) {
        'published' => ['success-solid', 'o-check-circle', $load->current_version > 0 ? 'v'.$load->current_version : null, 'publicada'],
        'closed' => ['success', 'o-lock-closed', null, LoadClosing::statusLabel($load).', falta publicar'],
        'ready' => ['success', 'o-check', null, 'lista para cerrar'],
        default => $missing > 0
            ? [$progress->hard > 0 ? 'error' : 'warning', $progress->hard > 0 ? 'o-x-circle' : 'o-exclamation-triangle', $progress->blockingGroups > 0 ? (string) $progress->blockingGroups : null, LoadClosing::statusLabel($load).', '.mb_strtolower(LoadClosing::missingPhrase($progress->blockingGroups, $progress->procedures()))]
            : ['info', 'o-arrow-path-rounded-square', null, LoadClosing::statusLabel($load).', sin faltantes'],
    };
    $label = "{$load->campus->name}: {$state}";
@endphp
<button type="button" wire:click.stop="open('{{ $load->department_id }}', '{{ $load->campus->code }}')" aria-label="{{ $label }}" title="{{ $label }}" @if ($selected) aria-current="true" @endif
    @class(['rounded-selector focus-visible:outline-2 focus-visible:outline-offset-2 focus-visible:outline-primary', 'outline-2 outline-offset-1 outline-primary' => $selected])
    data-testid="{{ $testid ?? 'campus-badge' }}-{{ $load->department->code }}-{{ $load->campus->code }}">
    <x-core::status-badge :tone="$tone" :icon="$icon" :label="$load->campus->code" class="font-mono [&_svg]:h-3.5 [&_svg]:w-3.5">@if ($extra)<span aria-hidden="true">·</span> {{ $extra }}@endif</x-core::status-badge>
</button>
