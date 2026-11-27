{{--
    Elección previa con más horas frente a grupo que su plaza (SEL-16 ampliada, tarea 1.45): aviso en línea, sin diálogo. Si las horas
    están arriba de la banda y falta confirmar: sus horas, su base, cuánto se pasa, la casilla de responsabilidad de
    SEL-05 y «Confirmar». Ya confirmada: «Confirmaste tus horas el …». Espera $prior (PriorChoiceHoursQuery) o null.
--}}
@use('App\Modules\Staffing\Rules\LoadBand')
@if ($prior !== null && ($prior['needs_confirmation'] || $prior['confirmed_at'] !== null))
    @if ($prior['confirmed_at'] !== null)
        <div role="note" class="alert alert-success alert-soft items-start text-sm" data-testid="prior-confirmed">
            <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
            <div>
                <p class="font-semibold">Confirmaste tus horas el {{ $prior['confirmed_at']->setTimezone(config('app.timezone'))->isoFormat('D [de] MMMM [de] YYYY') }}</p>
                <p>Tus {{ $prior['hours'] }} h de elección previa quedan justificadas.</p>
            </div>
        </div>
    @else
        <div role="note" class="alert alert-warning alert-soft items-start text-sm" data-testid="prior-confirm">
            <x-icon name="o-exclamation-triangle" class="mt-0.5 h-5 w-5" aria-hidden="true" />
            <div class="min-w-0 flex-1 space-y-3">
                <div>
                    <p class="font-semibold">Confirma tus horas de la elección previa</p>
                    <p>Tus grupos suman <b class="font-mono">{{ $prior['hours'] }}</b> h y tus horas frente a grupo son <b class="font-mono">{{ $prior['base'] }}</b> h ({{ $prior['base'] - LoadBand::MARGIN }} a {{ $prior['base'] + LoadBand::MARGIN }}): @if ($prior['over_by'] > 0)pasas tu margen por <b class="font-mono">{{ $prior['over_by'] }}</b> h.@else ya quedas dentro de tu margen; confirma para cerrar este pendiente.@endif</p>
                </div>
                <x-checkbox wire:model="priorAcknowledged" label="Entiendo que es mi responsabilidad cubrir mis horas frente a grupo de acuerdo con mi plaza." class="checkbox-primary" aria-describedby="prior-hint prior-error" data-testid="prior-acknowledge" />
                <p id="prior-hint" class="text-[12.5px] text-neutral">Marca la casilla para confirmar.</p>
                @error('acknowledged')
                    <p id="prior-error" class="text-sm font-semibold text-error" role="alert">{{ $message }}</p>
                @enderror
                <x-button label="Confirmar" icon="o-check" class="btn-primary" wire:click="confirmPrior({{ $prior['hours'] }})" spinner="confirmPrior" data-testid="prior-confirm-submit" />
            </div>
        </div>
    @endif
@endif
