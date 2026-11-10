{{--
    El diálogo de confirmar de toda la aplicación (ADR-0023: nunca los diálogos del navegador). Va una vez en cada
    layout. Lo abre cualquier botón o enlace con `x-confirm="mensaje"` (directiva de resources/js/app.js) en lugar de
    `wire:confirm`: el clic se detiene, se muestra este `x-modal` y, al confirmar, el mismo elemento se vuelve a
    pulsar, así su `wire:click` o su enlace corren como siempre. Opcionales en el disparador: `data-confirm-title` (por
    omisión, el texto del botón), `data-confirm-button` (por omisión, el título) y `data-confirm-tone`: `error` para
    lo que borra o desactiva, `warning` para lo que cambia algo que otros ven, `primary` para lo demás. El foco abre
    en «Cancelar», que es lo seguro; Esc o un clic fuera también cancelan.
--}}
<div x-data="nexusConfirm" x-on:nexus-confirm.window="show($event.detail)" data-testid="confirm-dialog">
    <x-modal id="nexus-confirm" box-class="max-w-md" x-on:close="cancel()">
        <div class="grid gap-2 pe-8">
            <h2 class="text-lg font-bold leading-snug" x-text="title"></h2>
            <p class="text-sm text-neutral" x-text="message"></p>
        </div>

        <x-slot:actions>
            <div class="flex w-full flex-col-reverse gap-2 sm:w-auto sm:flex-row">
                <button type="button" class="btn btn-ghost max-sm:h-11" x-ref="cancel" x-on:click="cancel()" data-testid="confirm-cancel">Cancelar</button>
                <button type="button" class="btn max-sm:h-11" x-bind:class="toneClass" x-on:click="accept()" x-text="button" data-testid="confirm-accept"></button>
            </div>
        </x-slot:actions>
    </x-modal>
</div>
