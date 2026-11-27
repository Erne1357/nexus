{{--
    La publicación de la carga (tarea 4.3; DESIGN.md, «Publicación»): academic-load::components.publish-load, una
    `<section>` con `@container` y su línea de versiones, a todo el ancho debajo de la línea de estados; se acomoda sola,
    de lado en la pantalla y vertical en el drawer, que es angosto. Pinta su propio motivo cuando no se puede publicar y
    avisa con `load-published` para que la página vuelva a leer la carga. La llave lleva el estado para que se monte otra
    vez al cerrar, reabrir o publicar. Solo para quien ve la carga (`$visible`): el componente autoriza `view` al
    montarse. `$load`, `$visible` y `$key` (publish en la pantalla, publish-drawer en el drawer).
--}}
@if ($visible)
    <div class="min-w-0" data-testid="load-publication">
        <livewire:academic-load::components.publish-load :load="$load" wire:key="{{ $key }}-{{ $load->id }}-{{ $load->status->value }}" />
    </div>
@endif
