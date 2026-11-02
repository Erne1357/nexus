{{--
    El diálogo de regresar una parte (academic-load::pages.inbox), dentro de x-modal: el comentario es obligatorio
    (hasta 1000 caracteres) y le llega al coordinador. Confirmar llama a `returnPart`.
--}}
<div class="space-y-4">
    <h2 id="return-title" class="text-xl font-bold">Regresar la parte de {{ $target['career']->code }}</h2>
    <x-textarea label="Comentario para el coordinador" wire:model="comment" rows="4" maxlength="1000" data-testid="return-comment" />
    <div class="flex justify-end gap-2">
        <x-button label="Cancelar" class="btn-ghost" wire:click="$set('showReturn', false)" />
        <x-button label="Regresar" icon="o-arrow-uturn-left" class="btn-error" wire:click="returnPart" spinner="returnPart" data-testid="confirm-return" />
    </div>
</div>
