{{--
    El lugar del docente en un grupo de la oferta: «Asignado a ti» (info), su lugar como cifra («14 de 139», neutra, con
    las cifras en Mono) o, si cuenta en «Entre tus 3 primeros», en success con `o-star`; sin ranking, «Sin lugar».
--}}
@if ($group['is_mine'])
    <x-core::status-badge tone="info" label="Asignado a ti" icon="o-check-circle" />
@elseif ($group['rank'] === null)
    <span class="badge badge-neutral badge-soft h-6.5 whitespace-nowrap font-medium" data-testid="offer-position">Sin lugar</span>
@else
    <span @class(['badge badge-soft h-6.5 gap-1 whitespace-nowrap font-medium', 'badge-success' => $group['top'], 'badge-neutral' => ! $group['top']]) data-testid="offer-position">
        @if ($group['top'])
            <x-icon name="o-star" class="h-3.5 w-3.5" aria-hidden="true" />
        @endif
        <span class="font-mono">{{ $group['rank'] }}</span> de <span class="font-mono">{{ $group['total'] }}</span>
    </span>
@endif
