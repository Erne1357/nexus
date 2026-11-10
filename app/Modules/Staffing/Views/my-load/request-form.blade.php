{{--
    El modal «Solicitar cambio» de Mi carga (tarea 3.11, maqueta C), dentro de x-modal: el grupo (sus grupos del
    periodo; los que no se pueden pedir, deshabilitados con su motivo) y los campos de la solicitud. Enviar llama a
    `sendRequest`.
--}}
<form wire:submit="sendRequest" class="space-y-5" novalidate>
    <h2 id="request-title" class="text-xl font-bold">Solicitar cambio</h2>

    @include('staffing::change-requests.group-picker', ['groupOptions' => $requestOptions])

    @include('staffing::change-requests.request-fields')

    <div class="flex flex-wrap justify-end gap-2 border-t border-base-300 pt-4">
        <x-button label="Cancelar" class="btn-ghost max-md:h-11 max-md:min-h-11" wire:click="$set('showRequest', false)" />
        <x-button label="Enviar solicitud" type="submit" icon="o-paper-airplane" class="btn-primary max-md:h-11 max-md:min-h-11" spinner="sendRequest" data-testid="send-request" />
    </div>
</form>
