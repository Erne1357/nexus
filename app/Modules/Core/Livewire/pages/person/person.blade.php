{{--
    Ficha de una persona en «Personas y puestos» (DESIGN.md, «Personas y puestos»), la maqueta C «La ficha de la
    persona»: enlace de regreso, iniciales, nombre, usuario, insignia con sus puestos vigentes y «Nombrar» a la derecha;
    el buscador de otra persona y el último nombramiento; cuatro cifras; la «Línea de vigencias» y las tarjetas de
    «Nombramientos» (core::people.timeline y core::people.assignment); y, en la columna lateral (debajo desde
    1200 px hacia abajo), «¿Quién ocupa un puesto?» y la bitácora de sus puestos. «Nombrar» abre un modal que pide solo
    el alcance que el puesto necesita.
--}}
@php
    $person = $record->person;
    $unitLabel = $roleCase?->unitKind() === \App\Modules\Core\Enums\OrgUnitKind::Subdireccion ? 'Subdirección' : 'Departamento académico';
    $whoUnitLabel = $whoRoleCase?->unitKind() === \App\Modules\Core\Enums\OrgUnitKind::Subdireccion ? 'Subdirección' : 'Departamento académico';
    $columns = $roleCase?->scopeColumns() ?? [];
    $whoColumns = $whoRoleCase?->scopeColumns() ?? [];
    $hints = [
        'admin' => 'Este puesto no tiene alcance: vale en todo el sistema.',
        'subdireccion' => 'Este puesto pide la Subdirección Académica. Tiene un solo titular vigente.',
        'coordinador' => 'Este puesto pide campus y carrera.',
    ];
@endphp

<div class="space-y-5">
    <div class="flex flex-wrap items-start justify-between gap-x-6 gap-y-3">
        <div class="flex min-w-0 items-center gap-4">
            <span class="flex h-14 w-14 shrink-0 items-center justify-center rounded-full bg-base-300 text-lg font-semibold" aria-hidden="true">{{ $record->initials() }}</span>

            <div class="min-w-0">
                <a href="{{ route('core.people') }}" wire:navigate data-testid="back-to-people" class="inline-flex items-center gap-1.5 text-sm font-medium text-primary max-md:min-h-11">
                    <x-icon name="o-arrow-left" class="h-4 w-4" />Personas y puestos
                </a>
                <h1 class="text-[1.625rem] font-bold leading-tight md:text-3xl">{{ $person->name }}</h1>
                <p class="mt-1 flex flex-wrap items-center gap-x-2 gap-y-1">
                    <span class="text-neutral">usuario <span class="font-mono tabular-nums">{{ $person->username }}</span></span>

                    @if ($record->current > 0)
                        <x-core::status-badge tone="success" :label="$record->current === 1 ? 'Con 1 puesto vigente' : 'Con '.$record->current.' puestos vigentes'" />
                    @else
                        <x-core::status-badge tone="neutral" label="Sin puesto vigente" />
                    @endif

                    @unless ($person->is_active)
                        <x-core::status-badge tone="neutral" label="Usuario inactivo" />
                    @endunless
                </p>
            </div>
        </div>

        <div class="flex flex-col items-end gap-1 max-md:w-full max-md:items-stretch">
            @if ($canAppoint)
                <x-button label="Nombrar" icon="o-plus" class="btn-primary max-md:h-11" wire:click="openAppoint" spinner="openAppoint" data-testid="appoint" />
            @else
                <x-button label="Nombrar" icon="o-plus" class="btn-primary max-md:h-11" disabled data-testid="appoint" />
                <p class="text-sm text-neutral" data-testid="appoint-blocker">El usuario está inactivo.</p>
            @endif
        </div>
    </div>

    <div class="flex flex-wrap items-center gap-x-4 gap-y-2 border-t border-base-300 pt-4">
        <div class="w-full md:w-80">
            <x-input name="find" wire:model.live.debounce.300ms="find" placeholder="Buscar otra persona: nombre o número" aria-label="Buscar otra persona" icon="o-magnifying-glass" clearable />
        </div>

        @if ($record->lastAppointment !== null)
            <p class="text-sm text-neutral" data-testid="last-appointment">
                Último nombramiento: <span class="font-mono tabular-nums">{{ $record->lastAppointment['on']->format('d/m/Y') }}</span>@if ($record->lastAppointment['by'] !== null), por {{ $record->lastAppointment['by'] }}@endif.
            </p>
        @endif
    </div>

    @if ($found->isNotEmpty())
        <ul class="flex flex-wrap gap-2" data-testid="found-people" aria-label="Personas encontradas">
            @foreach ($found as $other)
                <li wire:key="found-{{ $other->id }}">
                    <a href="{{ route('core.people.show', $other->id) }}" wire:navigate class="btn btn-outline btn-sm max-md:h-11">
                        {{ $other->name }} <span class="font-mono tabular-nums text-neutral">{{ $other->username }}</span>
                    </a>
                </li>
            @endforeach
        </ul>
    @elseif (trim($find) !== '')
        <p class="text-sm text-neutral" data-testid="found-none">Nadie coincide con «{{ $find }}».</p>
    @endif

    <dl class="grid overflow-hidden rounded-box border border-base-300 bg-base-100 max-md:divide-y md:grid-cols-4 md:divide-x divide-base-300" data-testid="facts">
        <div class="px-4 py-3">
            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Puestos vigentes</dt>
            <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums">{{ $record->current }}</dd>
        </div>
        <div class="px-4 py-3">
            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Anteriores</dt>
            <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums">{{ $record->past }}</dd>
        </div>
        <div class="px-4 py-3">
            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Con nombramiento desde</dt>
            <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums">{{ $record->since?->format('d/m/Y') ?? '—' }}</dd>
        </div>
        <div class="px-4 py-3">
            <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Próximo vencimiento</dt>
            @if ($record->nextExpiry !== null)
                <dd class="mt-1 font-mono text-2xl font-semibold tabular-nums">{{ $record->nextExpiry['on']->format('d/m/Y') }}</dd>
                <dd class="text-sm text-neutral">{{ $record->nextExpiry['summary'] }}</dd>
            @else
                <dd class="mt-1 text-base text-neutral">Sin fecha de fin</dd>
            @endif
        </div>
    </dl>

    <div class="grid gap-5 min-[1200px]:grid-cols-[minmax(0,1fr)_22rem]">
        <div class="min-w-0 space-y-5">
            <section aria-labelledby="vigencias-title" class="rounded-box border border-base-300 bg-base-100 p-4">
                <h2 id="vigencias-title" class="mb-3 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Línea de vigencias</h2>
                @include('core::people.timeline')
            </section>

            <section aria-labelledby="nombramientos-title" class="rounded-box border border-base-300 bg-base-100 p-4">
                <h2 id="nombramientos-title" class="mb-3 flex items-center gap-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">
                    Nombramientos
                    <span class="rounded bg-base-200 px-1.5 font-mono tracking-normal">{{ count($record->assignments) }}</span>
                </h2>

                <div class="space-y-3" data-testid="assignments">
                    @forelse ($record->assignments as $row)
                        <div wire:key="assignment-{{ $row['assignment']->id }}">
                            @include('core::people.assignment', ['row' => $row])
                        </div>
                    @empty
                        <p class="text-neutral" data-testid="assignments-empty">Todavía no tiene nombramientos.</p>
                    @endforelse
                </div>
            </section>
        </div>

        <div class="min-w-0 space-y-5">
            <section aria-labelledby="who-title" class="rounded-box border border-base-300 bg-base-100 p-4" data-testid="who">
                <h2 id="who-title" class="mb-3 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">¿Quién ocupa un puesto?</h2>

                <div class="space-y-3">
                    <x-select label="Puesto" wire:model.live="whoRole" :options="$whoRoles" />

                    @if ($whoColumns !== [])
                        <div class="grid gap-3 sm:grid-cols-2 min-[1200px]:grid-cols-1 min-[1400px]:grid-cols-2">
                            @if (in_array('campus_id', $whoColumns, true))
                                <x-select label="Campus" wire:model.live="whoCampus" :options="$whoOptions['campuses']" placeholder="Elige" />
                            @endif

                            @if (in_array('career_id', $whoColumns, true))
                                <x-select label="Carrera" wire:model.live="whoCareer" :options="$whoOptions['careers']" placeholder="Elige" />
                            @endif

                            @if (in_array('org_unit_id', $whoColumns, true))
                                <x-select :label="$whoUnitLabel" wire:model.live="whoUnit" :options="$whoOptions['units']" placeholder="Elige" />
                            @endif
                        </div>
                    @endif

                    @if ($who !== null)
                        <div data-testid="who-result" aria-live="polite">
                            @if ($who['state'] === 'incomplete')
                                <p class="text-sm text-neutral">Elige el alcance para ver quién ocupa el puesto.</p>
                            @elseif ($who['state'] === 'none')
                                <x-alert class="alert-warning" icon="o-exclamation-triangle">
                                    <span><b>Sin {{ mb_strtolower($whoRoleCase->label()) }} vigente.</b><br>Nadie ocupa {{ $who['summary'] }} hoy.</span>
                                </x-alert>
                            @else
                                <div class="space-y-2">
                                    @foreach ($who['holders'] as $holder)
                                        <x-alert class="alert-success" icon="o-check-circle" wire:key="holder-{{ $holder->id }}">
                                            <span>
                                                <a href="{{ route('core.people.show', $holder->user_id) }}" wire:navigate class="font-semibold underline">{{ $holder->user->name }}</a><br>
                                                <span class="font-mono tabular-nums">{{ $holder->user->username }}</span> · desde <span class="font-mono tabular-nums">{{ $holder->valid_from->format('d/m/Y') }}</span>
                                            </span>
                                        </x-alert>
                                    @endforeach
                                </div>
                            @endif
                        </div>
                    @endif
                </div>
            </section>

            <section aria-labelledby="log-title" class="rounded-box border border-base-300 bg-base-100 p-4" data-testid="log">
                <h2 id="log-title" class="mb-3 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Bitácora de sus puestos</h2>

                @if ($record->log === [])
                    <p class="text-sm text-neutral">Todavía no hay movimientos.</p>
                @else
                    <ul class="space-y-3 text-sm">
                        @foreach ($record->log as $entry)
                            <li>
                                <span class="font-mono tabular-nums">{{ $entry['on']->format('d/m/Y') }}</span>
                                <span class="block">{{ $entry['description'] }}@if ($entry['by'] !== null) <span class="text-neutral">· por {{ $entry['by'] }}</span>@endif</span>
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>
        </div>
    </div>

    <x-modal wire:model="showModal" :title="'Nombrar a '.$person->name" subtitle="El formulario pide solo el alcance que el puesto necesita." box-class="max-w-xl" separator>
        <x-form wire:submit="appoint" no-separator>
            @error('user')
                <x-alert :title="$message" icon="o-x-circle" class="alert-error" />
            @enderror

            <div>
                <x-select label="Puesto" name="role" wire:model.live="role" :options="$offered" placeholder="Elige un puesto" />
                <p class="mt-1 text-sm text-neutral">Las jefaturas y el puesto de docente se manejan en la ficha del docente.</p>
            </div>

            @if ($roleCase !== null)
                <p class="text-sm" data-testid="role-hint">{{ $hints[$roleCase->value] ?? '' }}</p>
            @endif

            @if ($columns !== [])
                <div class="grid gap-4 sm:grid-cols-2">
                    @if (in_array('campus_id', $columns, true))
                        <div data-testid="field-campus">
                            <x-select label="Campus" name="campus" wire:model.live="campus" :options="$options['campuses']" placeholder="Elige el campus" />
                        </div>
                    @endif

                    @if (in_array('career_id', $columns, true))
                        <div data-testid="field-career">
                            <x-select label="Carrera" name="career" wire:model="career" :options="$options['careers']" :placeholder="$campus === '' ? 'Elige primero el campus' : 'Elige la carrera'" />
                        </div>
                    @endif

                    @if (in_array('org_unit_id', $columns, true))
                        <div data-testid="field-unit" class="sm:col-span-2">
                            <x-select :label="$unitLabel" name="unit" wire:model="unit" :options="$options['units']" placeholder="Elige" />
                        </div>
                    @endif
                </div>
            @endif

            <div class="grid gap-4 sm:grid-cols-2">
                <x-input label="Desde" name="from" type="date" wire:model="from" />
                <x-input label="Hasta (opcional)" name="to" type="date" wire:model="to" />
            </div>

            <x-slot:actions>
                <x-button label="Cancelar" @click="$wire.showModal = false" />
                <x-button label="Nombrar" type="submit" icon="o-plus" class="btn-primary" spinner="appoint" data-testid="appoint-submit" />
            </x-slot:actions>
        </x-form>
    </x-modal>
</div>
