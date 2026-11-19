{{--
    Quitar el docente de un grupo (UnassignTeacher; incidencia F-02 del ensayo 8.11), con el x-modal de confirmar
    (DESIGN.md, «Confirmar»; ADR-0023): el título dice a quién y de qué grupo, debajo lo que pasa, y el motivo, que la
    Action exige (supuesto por confirmar). «Quitar docente» se habilita al escribirlo; lo que rechaza la Action (la carga
    en otro estado, el grupo ya sin docente) se ve arriba de los botones. Tono `warning`: cambia algo que otros ven (la
    carga del docente). En celular los botones van apilados a todo el ancho, con la acción arriba y, sobre ella, «Escribe
    el motivo para quitarlo.» mientras falta.
--}}
<x-modal wire:model="showUnassign" box-class="max-w-md" aria-labelledby="unassign-title" aria-describedby="unassign-consequence" data-testid="unassign-modal">
    @if ($unassigning !== null)
        <form wire:submit="unassign" class="grid gap-4" novalidate x-data="{ filled: false }" x-init="$watch(() => $wire.showUnassign, () => filled = false)">
            <div class="grid gap-2 pe-8">
                <h2 id="unassign-title" class="text-balance text-lg font-bold leading-snug">Quitar a {{ $unassigning['teacher'] }} del grupo {{ $unassigning['code'] }}</h2>
                <p id="unassign-consequence" class="text-sm text-neutral">
                    <span class="font-mono font-semibold text-base-content">{{ $unassigning['code'] }}</span> · {{ $unassigning['subject'] }} queda sin docente. La asignación pasa al historial como «Quitada», con tu motivo.
                </p>
            </div>

            <x-textarea label="Motivo" wire:model="unassignReason" rows="3" placeholder="Por ejemplo: El grupo sobra y se cierra en el re-análisis." x-on:input="filled = $event.target.value.trim() !== ''" data-testid="unassign-reason" />

            @error('unassign')
                <p class="flex items-start gap-2 rounded-box bg-error/10 px-3 py-2 text-sm text-error" role="alert" data-testid="unassign-failure">
                    <x-icon name="o-x-circle" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{{ $message }}</span>
                </p>
            @enderror

            <div class="flex flex-col-reverse gap-2 sm:flex-row sm:items-center sm:justify-end">
                <p class="text-sm text-neutral max-sm:order-1 sm:me-auto" x-show="! filled">Escribe el motivo para quitarlo.</p>
                <button type="button" wire:click="cancelUnassign" class="btn btn-ghost max-sm:h-11">Cancelar</button>
                <x-button type="submit" label="Quitar docente" icon="o-user-minus" class="btn-warning max-sm:h-11" spinner="unassign" x-bind:disabled="! filled" data-testid="confirm-unassign" />
            </div>
        </form>
    @endif
</x-modal>
