{{--
    «N por validar» de un plan (academic::pages.plans), en la tabla y en los renglones del celular: la insignia de
    advertencia de Core dentro de un enlace a «Por validar» del plan. Solo con materias por validar
    (`pending_placements_count`, que la lista trae para quien las valida y que no cuenta las de una retícula histórica,
    D18).
--}}
@if (($plan->pending_placements_count ?? 0) > 0)
    <a href="{{ route('academic.plans.show', [$plan, 'vista' => 'por-validar']) }}" wire:navigate data-pending-plan="{{ $plan->code }}"
        class="inline-flex rounded-badge focus-visible:outline-2 focus-visible:outline-offset-2 [&>.badge]:hover:border-warning">
        <x-core::status-badge tone="warning" icon="o-exclamation-triangle" :label="$plan->pending_placements_count.' por validar'" />
    </a>
@endif
