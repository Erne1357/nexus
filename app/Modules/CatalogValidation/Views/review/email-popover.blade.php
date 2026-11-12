{{--
    El recuadro del correo institucional de un docente (AC-06; opción A): se abre junto a su nombre desde «Agregar» o
    desde «Acciones», encima de todo (`popover`, que la caja con overflow de la tabla no corta), con el usuario y el
    dominio institucional fijo, a qué sirve y «Guardar». Guardar lo manda a saveEmail() (CorrectRecord, que lo marca
    como revisado, CV7); un error lo dice aquí sin cerrar. Esc, un clic fuera o «Cancelar» lo cierran y el foco vuelve a
    lo que lo abrió. Lo lleva el Alpine `catalogReviewList`; `wire:ignore` evita que Livewire lo repinte abierto.
--}}
<div wire:ignore>
    <div
        x-ref="emailPop"
        popover
        role="dialog"
        aria-labelledby="review-email-label"
        x-on:toggle="emailToggled($event)"
        class="inset-auto m-0 w-[min(24rem,calc(100vw-1rem))] rounded-box border border-base-300 bg-base-100 p-3.5 text-base-content shadow-lg"
        data-testid="email-popover"
    >
        <form x-on:submit.prevent="saveEmail()" class="space-y-3">
            <div class="space-y-1.5">
                <label id="review-email-label" for="review-email-input" class="block text-sm font-semibold">Correo institucional</label>
                <div class="join w-full">
                    <input id="review-email-input" x-ref="emailInput" x-model="email.value" type="text" inputmode="email" autocomplete="off" spellcheck="false" placeholder="usuario" class="input join-item min-w-0 flex-1 font-mono max-md:h-11" aria-describedby="review-email-help review-email-error" data-testid="institutional-email">
                    <span class="join-item flex items-center border border-base-300 bg-base-200 px-2.5 font-mono text-[12.5px] text-neutral">{{ '@'.$emailDomain }}</span>
                </div>
                <p id="review-email-help" class="text-xs text-neutral"><span x-text="email.name"></span>. Le llega su invitación a Nexus al validar el catálogo; al guardar queda revisado.</p>
                <p id="review-email-error" class="text-sm text-error" role="alert" x-show="email.error" x-text="email.error" data-testid="email-error"></p>
            </div>
            <div class="flex justify-end gap-2">
                <button type="button" class="btn btn-ghost btn-sm max-md:h-11" x-on:click="closeEmail()">Cancelar</button>
                <button type="submit" class="btn btn-primary btn-sm max-md:h-11" x-bind:disabled="email.saving" data-testid="save-email">
                    <span class="loading loading-spinner loading-xs" x-show="email.saving"></span>Guardar
                </button>
            </div>
        </form>
    </div>
</div>
