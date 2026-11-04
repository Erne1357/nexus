{{--
    Panel de la unidad elegida en el Organigrama (DESIGN.md, «Organigrama»): la cabecera con el nombre, «clave · tipo»,
    su estado y su ruta; «Titular» (con «Ver en Personas y puestos»), «Puestos vigentes aquí», «Personas», «Unidades
    hijas» (chips que la eligen) y «Acciones». Lo que no se puede hacer queda deshabilitado con su motivo escrito
    debajo; un departamento académico solo dice dónde se edita (AZ-S13). Desde 1400 px va a la derecha del diagrama,
    pegado arriba al desplazarse; antes, debajo. El formulario (core::org-chart.unit-form) va fuera del panel, para que
    su `sticky` no lo encierre.
--}}
@php
    $unit = $detail->unit;
    $initials = collect(preg_split('/\s+/u', trim($detail->titular['name'] ?? '')))->take(2)->map(fn (string $word): string => mb_strtoupper(mb_substr($word, 0, 1)))->implode('');
    $label = 'text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted';
@endphp

<div class="min-w-0">
    <aside
        id="org-unit-panel"
        aria-labelledby="org-unit-title"
        data-testid="org-unit-panel"
        class="scroll-mt-24 rounded-box border border-base-300 bg-base-100 p-4 md:p-5 min-[1400px]:sticky min-[1400px]:top-21 min-[1400px]:max-h-[calc(100vh-6.5rem)] min-[1400px]:overflow-y-auto"
    >
        <div class="grid gap-4.5">
            <div class="flex items-start gap-3">
                <span class="grid h-12 w-12 shrink-0 place-items-center rounded-box bg-base-200" aria-hidden="true">
                    <x-icon :name="$unit->kind === \App\Modules\Core\Enums\OrgUnitKind::DepartamentoAcademico ? 'o-lock-closed' : 'o-building-office-2'" class="h-6 w-6" />
                </span>

                <div class="min-w-0">
                    <h2 id="org-unit-title" class="text-[1.375rem] leading-tight font-bold tracking-[-0.01em]">{{ $unit->name }}</h2>
                    <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1 text-neutral">
                        <span><span class="font-mono">{{ $unit->code }}</span> · {{ $unit->kind->label() }}</span>
                        <x-core::status-badge :tone="$unit->is_active ? 'success' : 'neutral'" :label="$unit->is_active ? 'Activa' : 'Inactiva'" />
                    </p>
                </div>
            </div>

            @if ($detail->path !== [])
                <p class="-mt-1 text-sm text-neutral" data-testid="unit-path">{{ implode(' › ', [...$detail->path, $unit->name]) }}</p>
            @endif

            <section aria-labelledby="unit-titular-title">
                <h3 id="unit-titular-title" class="{{ $label }} mb-2">Titular</h3>

                @if ($detail->titular !== null)
                    <div class="flex items-center gap-3">
                        <span class="grid h-11 w-11 shrink-0 place-items-center rounded-full bg-base-300 font-semibold" aria-hidden="true">{{ $initials }}</span>
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $detail->titular['name'] }}</p>
                            <p class="text-sm text-neutral">{{ $detail->titular['summary'] }} · desde <span class="font-mono tabular-nums">{{ $detail->titular['from']->format('d/m/Y') }}</span></p>
                        </div>
                    </div>
                    <a href="{{ route('core.people.show', $detail->titular['userId']) }}" wire:navigate class="mt-2 inline-flex items-center gap-1.5 text-sm font-medium text-primary underline max-md:min-h-11" data-testid="unit-titular-link">
                        Ver en Personas y puestos
                    </a>
                @else
                    <p class="text-sm text-neutral" data-testid="unit-titular-note">{{ $detail->titularNote }}</p>
                @endif
            </section>

            <section aria-labelledby="unit-assignments-title">
                <div class="mb-2 flex items-center justify-between gap-2">
                    <h3 id="unit-assignments-title" class="{{ $label }} flex items-center gap-2">
                        Puestos vigentes aquí
                        <span class="rounded bg-base-200 px-1.5 font-mono tracking-normal">{{ count($detail->assignments) }}</span>
                    </h3>
                    <x-button label="Nombrar" icon="o-plus" class="btn-outline btn-sm max-md:h-11" wire:click="openAppoint" spinner="openAppoint" :disabled="$detail->appointBlocker !== null" data-testid="unit-appoint" />
                </div>

                @if ($detail->appointBlocker !== null)
                    <p class="-mt-1 mb-2 text-sm text-neutral" data-testid="unit-appoint-blocker">{{ $detail->appointBlocker }}</p>
                @endif

                @if ($detail->assignments === [])
                    <p class="text-sm text-neutral" data-testid="unit-assignments-empty">Nadie tiene un puesto vigente aquí.</p>
                @else
                    <ul class="divide-y divide-base-300 rounded-box border border-base-300" data-testid="unit-assignments">
                        @foreach ($detail->assignments as $row)
                            <li class="px-3 py-2.5" wire:key="unit-assignment-{{ $row['id'] }}" data-testid="unit-assignment-{{ $row['id'] }}">
                                <div class="flex items-start justify-between gap-3">
                                    <div class="min-w-0">
                                        <a href="{{ route('core.people.show', $row['userId']) }}" wire:navigate class="font-semibold hover:underline">{{ $row['name'] }}</a>
                                        <p class="text-sm text-neutral">{{ $row['summary'] }}</p>
                                    </div>
                                    <div class="grid shrink-0 justify-items-end gap-1">
                                        <x-core::status-badge :tone="$row['status']->tone()" :label="$row['status']->label()" />
                                        <span class="font-mono text-sm tabular-nums text-neutral">{{ $row['from']->format('d/m/Y') }}</span>
                                    </div>
                                </div>

                                @if ($endingId === $row['id'])
                                    @include('core::people.end-box', ['status' => $row['status'], 'from' => $row['from'], 'earliestLastDay' => $row['earliestLastDay']])
                                @else
                                    <div class="mt-2 flex flex-col items-start gap-1">
                                        @if ($row['endBlocker'] === null)
                                            <x-button label="Terminar…" wire:click="confirmEnd('{{ $row['id'] }}')" class="btn-outline btn-sm max-md:h-11" data-testid="end-{{ $row['id'] }}" />
                                        @else
                                            <x-button label="Terminar…" class="btn-outline btn-sm max-md:h-11" disabled data-testid="end-{{ $row['id'] }}" />
                                            <p class="text-sm text-neutral" data-testid="end-blocker">{{ $row['endBlocker'] }}</p>
                                        @endif
                                    </div>
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            <section aria-labelledby="unit-people-title">
                <h3 id="unit-people-title" class="{{ $label }} mb-1">Personas</h3>
                <p data-testid="unit-people">
                    <span class="font-mono text-xl font-semibold tabular-nums">{{ $detail->people }}</span>
                    <span class="text-neutral">con adscripción o puesto vigente en la unidad y en las que cuelgan de ella</span>
                </p>
            </section>

            @if ($detail->children !== [])
                <section aria-labelledby="unit-children-title">
                    <h3 id="unit-children-title" class="{{ $label }} mb-2 flex items-center gap-2">
                        Unidades hijas
                        <span class="rounded bg-base-200 px-1.5 font-mono tracking-normal">{{ count($detail->children) }}</span>
                    </h3>

                    <div class="flex flex-wrap gap-1.5">
                        @foreach ($detail->children as $child)
                            <button
                                type="button"
                                wire:key="unit-child-{{ $child->id }}"
                                x-on:click="$dispatch('org-unit-select', { id: '{{ $child->id }}' })"
                                data-testid="unit-child-{{ $child->code }}"
                                @class([
                                    'inline-flex min-h-7 cursor-pointer items-center gap-1.5 rounded border border-base-300 bg-base-200 px-2 py-0.5 text-start text-[13px] hover:bg-secondary focus-visible:outline-2 focus-visible:outline-offset-1 focus-visible:outline-primary max-md:min-h-9',
                                    'text-neutral' => ! $child->is_active,
                                ])
                            >
                                @if ($child->kind === \App\Modules\Core\Enums\OrgUnitKind::DepartamentoAcademico)
                                    <x-icon name="o-lock-closed" class="h-3.5 w-3.5 shrink-0" aria-hidden="true" />
                                @endif
                                {{ $child->name }}@unless ($child->is_active) · Inactiva @endunless
                            </button>
                        @endforeach
                    </div>
                </section>
            @endif

            <section aria-labelledby="unit-actions-title">
                <h3 id="unit-actions-title" class="{{ $label }} mb-2">Acciones</h3>

                <div class="flex flex-wrap gap-2 [&_.btn]:max-md:h-11">
                    <x-button label="Renombrar" icon="o-pencil" class="btn-outline btn-sm" wire:click="openEdit" spinner="openEdit" :disabled="$detail->lockedReason !== null" data-testid="unit-rename" />
                    <x-button label="Mover" icon="o-arrows-right-left" class="btn-outline btn-sm" wire:click="openMove" spinner="openMove" :disabled="$detail->moveBlocker !== null" data-testid="unit-move" />

                    @if ($detail->lockedReason === null)
                        <x-button label="Agregar hija" icon="o-plus" class="btn-outline btn-sm" wire:click="openCreate" spinner="openCreate" :disabled="$detail->childBlocker !== null" data-testid="unit-add-child" />
                    @endif

                    @if ($unit->is_active)
                        <x-button
                            label="Desactivar"
                            icon="o-minus-circle"
                            class="btn-outline btn-error btn-sm"
                            wire:click="setActive(false)"
                            wire:confirm="¿Desactivar {{ $unit->name }}? Deja de verse en el organigrama; queda en la bitácora."
                            spinner="setActive"
                            :disabled="$detail->deactivationBlockers !== []"
                            data-testid="unit-deactivate"
                        />
                    @else
                        <x-button label="Activar" icon="o-check-circle" class="btn-outline btn-sm" wire:click="setActive(true)" spinner="setActive" :disabled="$detail->activationBlocker !== null" data-testid="unit-activate" />
                    @endif
                </div>

                @if ($reasons !== [])
                    <ul class="mt-2 grid gap-1 text-sm text-neutral" data-testid="unit-blockers">
                        @foreach ($reasons as $reason)
                            <li>{{ $reason }}</li>
                        @endforeach
                    </ul>
                @endif

                @if ($errors->has('unit') && ! $showForm && ! $showAppoint)
                    <x-alert icon="o-x-circle" class="alert-error alert-soft mt-3" data-testid="unit-error">
                        @foreach ($errors->get('unit') as $message)
                            <span class="block">{{ $message }}</span>
                        @endforeach
                    </x-alert>
                @endif
            </section>
        </div>
    </aside>

    @include('core::org-chart.unit-form')
    @include('core::org-chart.appoint-form')
</div>
