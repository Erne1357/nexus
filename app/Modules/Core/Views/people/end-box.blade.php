{{--
    El recuadro «Terminar…» de un nombramiento (DESIGN.md, «Personas y puestos»), en la tarjeta de la ficha de la persona
    y en el renglón del panel del Organigrama: la fecha de fin, «Terminar nombramiento» y «Cancelar», la pista del primer
    último día posible (AZ-S12) y qué pasa según el estado. Espera `$status` (AssignmentStatus) y `$earliestLastDay`
    (?CarbonImmutable); el componente que lo incluye tiene `lastDay`, `end` y `cancelEnd`.
--}}
<form wire:submit="end" class="mt-3 space-y-2 rounded border border-base-300 bg-base-200/60 p-3" data-testid="end-box">
    <div class="flex flex-wrap items-end gap-2">
        <x-input label="Fecha de fin" name="lastDay" type="date" wire:model="lastDay" class="max-md:h-11" />
        <x-button label="Terminar nombramiento" type="submit" class="btn-primary btn-sm max-md:h-11" spinner="end" data-testid="end-submit" />
        <x-button label="Cancelar" wire:click="cancelEnd" class="btn-outline btn-sm max-md:h-11" />
    </div>

    @if ($earliestLastDay !== null && $earliestLastDay->isAfter(today()))
        <p class="text-sm text-neutral" data-testid="end-earliest">Terminarlo antes dejaría días sin admin: el primer último día posible es el {{ $earliestLastDay->format('d/m/Y') }}.</p>
    @endif

    <p class="text-sm text-neutral">
        @if ($status === \App\Modules\Core\Enums\AssignmentStatus::Scheduled)
            Todavía no empieza: si la fecha de fin es anterior a su inicio, el nombramiento se quita y queda en la bitácora.
        @else
            El puesto deja de ejercerse después de esa fecha. Queda en el historial y en la bitácora.
        @endif
    </p>
</form>
