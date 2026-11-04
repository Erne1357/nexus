{{--
    La confirmación de envío de la propuesta (academic-load::pages.proposal), dentro de x-modal: los totales que
    se envían y, si algún departamento con grupos pedidos aún no valida sus catálogos, un aviso que no bloquea
    (reglas-de-negocio.md, "Propuesta de carrera"). Confirmar llama a `submit`.
--}}
@php($resend = $proposal->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned)
<div class="space-y-4">
    <h2 id="submit-title" class="text-xl font-bold">{{ $resend ? 'Reenviar la propuesta' : 'Enviar la propuesta' }}</h2>
    <p class="text-neutral">Cada departamento recibe lo que se le pide y acepta o regresa su parte. Mientras esté enviada no se puede corregir.</p>

    <dl class="grid grid-cols-2 gap-3 rounded-box border border-base-300 px-4 py-3">
        @foreach ([['Materias con grupos', $overview->totals['subjects']], ['Grupos', $overview->totals['groups']]] as [$label, $value])
            <div>
                <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                <dd class="font-mono text-xl font-semibold tabular-nums">{{ $value }}</dd>
            </div>
        @endforeach
    </dl>

    @foreach ($overview->pendingCatalogs as $pending)
        <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="pending-catalogs"
            :title="$pending['department']->name.' aún no valida '.collect($pending['catalogs'])->map(fn ($catalog) => mb_strtolower($catalog->label()))->join(', ', ' y ').'.'" />
    @endforeach

    <div class="flex justify-end gap-2">
        <x-button label="Cancelar" class="btn-ghost" wire:click="$set('showSubmit', false)" />
        <x-button :label="$resend ? 'Reenviar' : 'Enviar'" icon="o-paper-airplane" class="btn-primary" wire:click="submit" spinner="submit" data-testid="confirm-submit" />
    </div>
</div>
