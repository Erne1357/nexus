{{--
    Las marcas que bajan a un docente al bloque final del ranking (reglas-de-negocio.md, «Un solo bloque final»): choque
    (HC-01) y no disponible (HC-09), con la insignia de su tono. Pasar su banda (HC-08) es aviso: va en notices.
--}}
@if ($candidate->isFlagged())
    <span class="flex flex-wrap gap-1.5" data-testid="marks">
        @foreach ($candidate->marks() as $mark => $markLabel)
            <x-core::status-badge :tone="$mark === 'choque' ? 'error' : 'draft'" :label="$markLabel" :icon="$mark === 'choque' ? 'o-x-circle' : 'o-no-symbol'" />
        @endforeach
    </span>
@endif
