{{--
    «Personal» de Comunicación y Difusión (CO-02): el personal de área de la unidad en renglones (el mismo en todos los
    tamaños), «Nombrar» en un x-drawer (buscar y elegir a la persona, desde y hasta) y «Terminar…» en un x-modal con el
    último día (ADR-0023).
--}}
<div>
    <x-header title="Personal" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-6!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-prose text-sm text-neutral!">
            Quién trabaja contigo en Nexus como personal de área de {{ $unit->name }}: maneja los avisos y los atajos de la pantalla de acceso y las fotos.
        </x-slot:subtitle>
    </x-header>

    <div class="mb-4 flex justify-end">
        <x-button label="Nombrar" icon="o-user-plus" class="btn-primary max-md:w-full" wire:click="openAppoint" spinner="openAppoint" data-testid="staff-appoint" />
    </div>

    <div class="overflow-hidden rounded-box border border-base-300 bg-base-100">
        @if ($appointments->isEmpty())
            <p class="px-4 py-6 text-center text-neutral" data-testid="staff-empty">Todavía no hay personal de área en {{ $unit->name }}.</p>
        @else
            <ul class="divide-y divide-base-300" data-testid="staff-list">
                @foreach ($appointments as $assignment)
                    <li wire:key="staff-{{ $assignment->id }}" class="flex flex-wrap items-center gap-x-4 gap-y-2 px-4 py-3" data-testid="staff-row">
                        <div class="min-w-0 flex-1">
                            <p class="font-semibold">{{ $assignment->user->name }}</p>
                            <p class="text-sm text-neutral">
                                <span class="font-mono tabular-nums">{{ $assignment->user->username }}</span>
                                · desde <span class="font-mono tabular-nums">{{ $assignment->valid_from->format('d/m/Y') }}</span>
                                @if ($assignment->valid_to !== null)
                                    hasta <span class="font-mono tabular-nums">{{ $assignment->valid_to->format('d/m/Y') }}</span>
                                @endif
                            </p>
                        </div>

                        @if ($assignment->valid_from->isFuture())
                            <x-core::status-badge tone="info" label="Por empezar" />
                        @else
                            <x-core::status-badge tone="success" label="Vigente" />
                        @endif

                        <x-button label="Terminar…" icon="o-stop-circle" class="btn-outline btn-sm max-md:h-11" wire:click="confirmEnd('{{ $assignment->id }}')" spinner="confirmEnd('{{ $assignment->id }}')" aria-label="Terminar el nombramiento de {{ $assignment->user->name }}" />
                    </li>
                @endforeach
            </ul>
        @endif
    </div>

    <x-drawer wire:model.live="showAppoint" right close-on-escape class="w-full max-w-xl p-0!" role="dialog" aria-modal="true" aria-labelledby="staff-appoint-title" data-testid="staff-appoint-drawer">
        @if ($showAppoint)
            <form wire:submit="appoint" class="flex h-dvh flex-col">
                <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
                    <div>
                        <h2 id="staff-appoint-title" class="text-xl font-bold">Nombrar personal de área</h2>
                        <p class="text-sm text-neutral">{{ $title }}</p>
                    </div>
                    <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" wire:click="closeAppoint" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
                </header>

                <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
                    <div class="grid gap-2" data-testid="staff-person">
                        @if ($person === null)
                            <x-input label="Persona" name="findPerson" wire:model.live.debounce.300ms="findPerson" placeholder="Nombre o usuario" icon="o-magnifying-glass" clearable data-testid="staff-find" />

                            @if ($found->isNotEmpty())
                                <ul class="flex flex-wrap gap-2" data-testid="staff-found" aria-label="Personas encontradas">
                                    @foreach ($found as $other)
                                        <li wire:key="staff-found-{{ $other->id }}">
                                            <button type="button" wire:click="choosePerson('{{ $other->id }}')" class="btn btn-outline btn-sm max-md:h-11" data-testid="staff-pick-{{ $other->username }}">
                                                {{ $other->name }} <span class="font-mono tabular-nums text-neutral">{{ $other->username }}</span>
                                            </button>
                                        </li>
                                    @endforeach
                                </ul>
                            @elseif (trim($findPerson) !== '')
                                <p class="text-sm text-neutral" data-testid="staff-found-none">Nadie del personal coincide con «{{ $findPerson }}».</p>
                            @endif
                        @else
                            <span class="text-sm font-medium">Persona</span>
                            <div class="flex flex-wrap items-center justify-between gap-2 rounded border border-base-300 bg-base-200/60 px-3 py-2">
                                <p data-testid="staff-chosen">{{ $person->name }} · <span class="font-mono tabular-nums">{{ $person->username }}</span></p>
                                <x-button label="Cambiar" wire:click="clearPerson" class="btn-outline btn-sm max-md:h-11" />
                            </div>
                        @endif

                        @error('personId')
                            <p class="text-sm text-error" data-testid="staff-person-error">{{ $message }}</p>
                        @enderror
                    </div>

                    <div class="grid gap-4 sm:grid-cols-2">
                        <x-input label="Desde" name="from" wire:model="from" type="date" />
                        <x-input label="Hasta (si tiene)" name="to" wire:model="to" type="date" hint="Vacío es sin fecha de fin." />
                    </div>
                </div>

                <footer class="flex justify-end gap-2 border-t border-base-300 px-5 py-3">
                    <x-button label="Cancelar" wire:click="closeAppoint" />
                    <x-button label="Nombrar" type="submit" icon="o-check" class="btn-primary" spinner="appoint" data-testid="staff-appoint-submit" />
                </footer>
            </form>
        @endif
    </x-drawer>

    <x-modal wire:model="showEnd" title="Terminar nombramiento" box-class="max-w-lg" separator>
        @if ($ending)
            <p>¿Terminar a <b>{{ $ending->user->name }}</b> como personal de área? Deja de ejercerse después del último día. Si empieza hoy o después y el último día es anterior a su inicio, se quita.</p>
            <x-input label="Último día" name="lastDay" wire:model="lastDay" type="date" class="mt-3" />
        @endif

        <x-slot:actions>
            <x-button label="Cancelar" wire:click="cancelEnd" />
            <x-button label="Terminar" icon="o-stop-circle" class="btn-error" wire:click="end" spinner="end" data-testid="staff-end-confirm" />
        </x-slot:actions>
    </x-modal>
</div>
