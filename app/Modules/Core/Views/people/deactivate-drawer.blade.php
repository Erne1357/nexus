{{--
    «Dar de baja» como cajón (1.22-C1, CF-05 y CF-09; ADR-0023), con la vista previa de DeactivationPreviewQuery: los
    puestos que terminan hoy o se quitan, con «Sin titular desde mañana» (o «Queda sin titular», si no empezaba) en los
    exclusivos (AZ-S7: hoy lo sigue ocupando, así que el relevo empieza mañana); el aviso de que sigue en el
    catálogo de docentes; por qué no se puede (uno mismo, un alumno o el último admin, AZ-S12); el motivo y una nota.
    Espera $person, $preview (nulo mientras está cerrado) y $reasons.
--}}
<form wire:submit="deactivate" class="flex h-dvh flex-col">
    <header class="flex items-start gap-3 border-b border-base-300 px-5 pt-5 pb-4">
        <div>
            <h2 id="deactivate-title" class="text-xl font-bold">Dar de baja a {{ $person->name }}</h2>
            <p class="text-sm text-neutral">Pierde el acceso y sus puestos terminan hoy.</p>
        </div>
        <button type="button" class="btn btn-ghost btn-sm btn-square ms-auto max-md:size-11" @click="$wire.showDeactivate = false" aria-label="Cerrar"><x-icon name="o-x-mark" class="h-5 w-5" aria-hidden="true" /></button>
    </header>

    <div class="flex-1 space-y-5 overflow-y-auto px-5 py-4">
        @error('user')
            <x-alert :title="$message" icon="o-x-circle" class="alert-error" data-testid="deactivate-error" />
        @enderror

        @if ($preview !== null)
            @if ($preview->blocker !== null)
                <x-alert :title="$preview->blocker" icon="o-no-symbol" class="alert-warning" data-testid="deactivate-blocker" />
            @endif

            <section aria-labelledby="deactivate-assignments-title">
                <h3 id="deactivate-assignments-title" class="mb-2 text-[11.5px] font-medium uppercase tracking-[0.09em] text-secondary-muted">Puestos</h3>

                @if ($preview->assignments === [])
                    <p class="text-sm text-neutral" data-testid="deactivate-no-assignments">No tiene puestos que terminar.</p>
                @else
                    <ul class="divide-y divide-base-300 rounded-box border border-base-300" data-testid="deactivate-assignments">
                        @foreach ($preview->assignments as $row)
                            <li class="flex flex-wrap items-center gap-x-3 gap-y-1 px-3 py-2.5 text-sm">
                                <span class="min-w-0 flex-1">{{ $row['summary'] }}</span>
                                <span class="text-neutral">{{ $row['removed'] ? 'Se quita: aún no empezaba' : 'Termina hoy' }}</span>
                                @if ($row['vacates'])
                                    <x-core::status-badge tone="warning" :label="$row['removed'] ? 'Queda sin titular' : 'Sin titular desde mañana'" />
                                @endif
                            </li>
                        @endforeach
                    </ul>
                @endif
            </section>

            @if ($preview->teacherNotice() !== null)
                <x-alert :title="$preview->teacherNotice()" icon="o-information-circle" class="alert-info" data-testid="deactivate-teacher" />
            @endif

            <div class="space-y-4">
                <x-select label="Motivo" name="deactivationReason" wire:model="deactivationReason" :options="$reasons" placeholder="Elige el motivo" data-testid="deactivate-reason" />
                <x-textarea label="Nota (opcional)" name="deactivationNote" wire:model="deactivationNote" rows="3" data-testid="deactivate-note" />
            </div>
        @endif
    </div>

    <footer class="flex flex-wrap items-center justify-between gap-3 border-t border-base-300 px-5 py-3">
        <p class="text-sm text-neutral">Queda en la bitácora con su motivo.</p>
        <div class="flex gap-2">
            <x-button label="Cancelar" @click="$wire.showDeactivate = false" class="max-md:h-11" />
            <x-button label="Dar de baja" type="submit" icon="o-user-minus" class="btn-error max-md:h-11" spinner="deactivate" :disabled="$preview?->blocker !== null" data-testid="deactivate-submit" />
        </div>
    </footer>
</form>
