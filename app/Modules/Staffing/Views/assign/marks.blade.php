{{--
    Las marcas que bajan a un docente al bloque final del ranking (reglas-de-negocio.md, «Un solo bloque final»): choque
    (HC-01), sin horas (HC-08) y no disponible (HC-09), con la insignia de su tono.
--}}
@if ($candidate->isFlagged())
    <span class="flex flex-wrap gap-1.5" data-testid="marks">
        @foreach ($candidate->marks() as $mark => $markLabel)
            @php($tone = match ($mark) { 'choque' => 'error', 'sin_horas' => 'warning', default => 'draft' })
            <x-core::status-badge :tone="$tone" :label="$markLabel" :icon="match ($mark) { 'choque' => 'o-x-circle', 'sin_horas' => 'o-clock', default => 'o-no-symbol' }" />
        @endforeach
    </span>
@endif
