{{--
    El diálogo de confirmar de toda la aplicación (ADR-0023: nunca los diálogos del navegador). Va una vez en cada
    layout. Lo abre un botón con `x-confirm="mensaje"` (directiva de resources/js/app.js) en lugar de `wire:confirm`:
    el clic se detiene, se muestra este `x-modal` y, al confirmar, el mismo botón se vuelve a pulsar, así su
    `wire:click` corre como siempre. Solo botones: un enlace con `wire:navigate` navega antes del clic, así que no
    alcanza a confirmar. Y solo dentro de Alpine (un componente Livewire o un `x-data`): fuera, la directiva no corre y
    la acción se haría sin preguntar. Opcionales en el disparador: `data-confirm-title` (por
    omisión, el texto del botón), `data-confirm-button` (por omisión, el título) y `data-confirm-tone`: `error` para
    lo que borra o desactiva, `warning` para lo que cambia algo que otros ven, `primary` para lo demás. El foco abre
    en «Cancelar», que es lo seguro; Esc o un clic fuera también cancelan.
--}}
<div x-data="nexusConfirm" x-on:nexus-confirm.window="show($event.detail)" data-testid="confirm-dialog">
    {{--
        Esc cancela solo este diálogo: no llega a los Esc de la ventana (el cajón o el editor de abajo), y se atiende en
        el evento `cancel` para que el foco vuelva al disparador en ese mismo momento. Sin el `x-trap` de MaryUI: el
        <dialog> modal ya deja inerte lo de abajo, y el trap devolvía el foco con un retraso, encima del nuestro.
    --}}
    <x-modal id="nexus-confirm" box-class="max-w-md" role="alertdialog" aria-labelledby="nexus-confirm-title" aria-describedby="nexus-confirm-message" without-trap-focus x-on:close="cancel()" x-on:cancel.prevent="cancel()" x-on:keydown.escape.stop="">
        <div class="grid gap-2 pe-8">
            <h2 id="nexus-confirm-title" class="text-lg font-bold leading-snug" x-text="title"></h2>
            <p id="nexus-confirm-message" class="text-sm text-neutral" x-text="message"></p>
        </div>

        <x-slot:actions>
            <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row">
                <button type="button" class="btn btn-ghost max-sm:h-11" x-ref="cancel" x-on:click="cancel()" data-testid="confirm-cancel">Cancelar</button>
                <button type="button" class="btn max-sm:h-11" x-bind:class="toneClass" x-on:click="accept()" x-text="button" data-testid="confirm-accept"></button>
            </div>
        </x-slot:actions>
    </x-modal>
</div>
