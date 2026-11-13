{{--
    La confirmación de envío de la propuesta (academic-load::pages.proposal), dentro de x-modal: los totales que se
    envían, por campus si hay más de uno (materias con grupos y grupos), a quién avisa (los demás coordinadores de la
    carrera en la modalidad, MS18, y los jefes de los departamentos con su parte por decidir) y, si algún departamento con
    grupos pedidos aún no valida sus catálogos, un aviso que no bloquea (reglas-de-negocio.md, "Propuesta de carrera").
    Confirmar llama a `submit`.
--}}
@php
    $resend = $proposal->status === \App\Modules\AcademicLoad\Enums\ProposalStatus::Returned;
    $multi = count($columns) > 1;
    $rows = collect($columns)->map(fn ($column, $key) => [
        'campus' => $column['campus'],
        'subjects' => collect($overview->subjects())->filter(fn ($row) => ($row->cells[$key] ?? null)?->requested_groups > 0)->count(),
        'groups' => $overview->totals['groups_by_campus'][$key] ?? 0,
    ])->values();
@endphp
<div class="space-y-4">
    <h2 id="submit-title" class="text-xl font-bold">{{ $resend ? 'Reenviar' : 'Enviar' }} la propuesta de <span class="font-mono">{{ $proposal->career->code }}</span> · {{ $proposal->modality->name }}</h2>
    <p class="text-neutral">Cada departamento recibe lo que se le pide y acepta o regresa su parte. Ya enviada, solo se corrige si un departamento la regresa.</p>

    <table class="table table-sm rounded-box border border-base-300" data-testid="submit-totals">
        <thead>
            <tr class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">
                <th scope="col"><span class="sr-only">Campus</span></th>
                <th scope="col" class="text-right">Materias con grupos</th>
                <th scope="col" class="text-right">Grupos</th>
            </tr>
        </thead>
        <tbody>
            @if ($multi)
                @foreach ($rows as $row)
                    <tr>
                        <th scope="row" class="font-normal"><span class="inline-flex items-center gap-1.5"><x-academic-load::campus-tag :campus="$row['campus']" />{{ $row['campus']?->name }}</span></th>
                        <td class="text-right font-mono tabular-nums">{{ $row['subjects'] }}</td>
                        <td class="text-right font-mono tabular-nums">{{ $row['groups'] }}</td>
                    </tr>
                @endforeach
            @endif
            <tr class="font-semibold">
                <th scope="row">Total</th>
                <td class="text-right font-mono text-base tabular-nums">{{ $overview->totals['subjects'] }}</td>
                <td class="text-right font-mono text-base tabular-nums">{{ $overview->totals['groups'] }}</td>
            </tr>
        </tbody>
    </table>

    @foreach ($overview->pendingCatalogs as $pending)
        <x-alert icon="o-exclamation-triangle" class="alert-warning alert-soft text-sm" data-testid="pending-catalogs"
            :title="$pending['department']->name.' aún no valida '.collect($pending['catalogs'])->map(fn ($catalog) => mb_strtolower($catalog->label()))->join(', ', ' y ').'.'" />
    @endforeach

    @if ($submitNotice)
        <p class="flex items-start gap-2 text-sm text-neutral" data-testid="submit-notice"><x-icon name="o-bell" class="mt-0.5 h-4 w-4 shrink-0" aria-hidden="true" /><span>{{ $submitNotice }}</span></p>
    @endif

    <div class="flex justify-end gap-2">
        <x-button label="Cancelar" class="btn-ghost" wire:click="$set('showSubmit', false)" />
        <x-button :label="$resend ? 'Reenviar propuesta' : 'Enviar propuesta'" icon="o-paper-airplane" class="btn-primary" wire:click="submit" spinner="submit" data-testid="confirm-submit" />
    </div>
</div>
