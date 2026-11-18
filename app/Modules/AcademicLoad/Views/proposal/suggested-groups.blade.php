{{--
    Los grupos que sugiere Nexus para una fila de la propuesta (ProposalForecastRow::effectiveGroups), en la tarjeta de «Revisar
    proyección» y en el panel: el número en Mono y, con `$noun`, «grupo» o «grupos». Con paquetes (tarea 5.6b) es lo sugerido
    efectivo y dice de qué se forma: «4 grupos (3 de paquetes + 1 suelto)».
--}}
@php
    $effectiveGroups = $forecastRow->effectiveGroups();
    $split = $forecastRow->packageSplit();
    $nounText = ($noun ?? false) ? ' '.($effectiveGroups === 1 ? 'grupo' : 'grupos') : '';
@endphp
<span class="font-mono font-semibold">{{ $effectiveGroups }}</span>{{ $nounText }}@if ($split) <span data-testid="suggested-split">(<span class="font-mono">{{ $split['packages'] }}</span> de paquetes + <span class="font-mono">{{ $split['loose'] }}</span> {{ $split['loose'] === 1 ? 'suelto' : 'sueltos' }})</span>@endif
