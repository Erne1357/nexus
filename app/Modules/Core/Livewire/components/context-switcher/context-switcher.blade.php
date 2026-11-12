{{-- Perfil y periodo activos, en la tarjeta de la credencial (DESIGN.md, "Estructura"): campos de 44 px con su etiqueta arriba. La credencial no elige campus (#415). --}}
<div class="grid gap-2.5">
    @if (count($roles) > 1)
        <div class="grid gap-1.5">
            <span id="nexus-role-label" class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">Actuando como</span>
            <div role="radiogroup" aria-labelledby="nexus-role-label" data-testid="role-switcher">
                <x-radio :options="$roles" wire:model="roleValue" wire:change="switchRole" class="radio-sm radio-primary" />
            </div>
        </div>
    @endif

    @if ($terms->isNotEmpty())
        <div class="grid gap-1">
            <span aria-hidden="true" class="text-[11.5px] font-medium tracking-[.09em] text-neutral uppercase">Periodo</span>
            <x-select
                name="term"
                aria-label="Periodo activo"
                :options="$terms"
                :placeholder="$termId === null ? 'Sin periodo' : null"
                wire:model="termId"
                wire:change="apply"
                class="h-11 text-base"
            />
        </div>
    @endif
</div>
