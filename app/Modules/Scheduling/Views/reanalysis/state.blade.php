{{--
    El estado de la carga en el re-análisis (opción B): la línea del ciclo con la línea del estado debajo y, a la derecha,
    las acciones, como el estado de la carga en «Cierre de la carga».

    - Publicada: «Ya puedes re-analizar la carga.» o «Todavía no se puede re-analizar.» con su motivo, e «Iniciar
      re-análisis» (con confirmación, ADR-0023; deshabilitado con su motivo escrito debajo, «Nada oculto»).
    - En re-análisis: desde cuándo y quién, que docentes y alumnos siguen viendo la versión vigente y cuántos grupos ya
      cambiaron («Ver» abre la lista), con «Cancelar re-análisis» (solo sin cambios, RA-10; si no, deshabilitado con su
      motivo) y «Cerrar re-análisis», que lleva a «Cierre de la carga».
    - Cerrada o aprobada después de un re-análisis: sigue en «Cierre de la carga».

    Quien solo mira no ve las acciones; el coordinador, tampoco el «Ver». `$state`, `$board`, `$abilities`, `$closingUrl`
    y `$full` (si ve toda la carga).
--}}
@php
    $load = $state->load;
    $version = $load->current_version;
    $changed = $board?->changedGroups() ?? 0;
    $cancelWhy = $changed > 0 ? 'Ya cambiaste '.$changed.' '.($changed === 1 ? 'grupo' : 'grupos').': ciérralo y publica la versión '.($version + 1).'.' : null;
    $when = fn ($at): string => $at->timezone(config('app.timezone'))->translatedFormat('j M Y');
    $approval = $load->modality->profile->requiresSubdireccionApproval;
@endphp
<section class="flex flex-wrap items-start justify-between gap-x-7 gap-y-4 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" aria-label="Estado del re-análisis" data-testid="reanalysis-state">
    <div class="grid min-w-0 flex-[1_1_34rem] gap-3.5">
        @include('scheduling::reanalysis.cycle', ['steps' => $state->steps])

        <p class="flex items-start gap-2 border-t border-base-300 pt-3 text-sm" data-testid="reanalysis-line">
            @switch($state->stage)
                @case('published')
                    <x-icon :name="$state->isAvailable() ? 'o-check-circle' : 'o-clock'" @class(['mt-0.5 h-4 w-4 shrink-0', 'text-success' => $state->isAvailable(), 'text-warning' => ! $state->isAvailable()]) aria-hidden="true" />
                    <span>
                        @if ($state->isAvailable())
                            <b class="font-semibold">Ya puedes re-analizar la carga.</b>
                            Llegaron <span class="font-mono tabular-nums">{{ number_format($state->progress['graded']) }}</span> de <span class="font-mono tabular-nums">{{ number_format($state->progress['total']) }}</span> calificaciones de {{ $state->gradesTerm }} y la proyección ya se recalculó.
                        @else
                            <b class="font-semibold">Todavía no se puede re-analizar.</b> {{ $state->firstBlocker() }}
                        @endif
                        @if (! $abilities['start'])
                            Lo inicia la jefatura de {{ $load->department->name }}.
                        @endif
                    </span>
                    @break
                @case('reanalysis')
                    <x-icon name="o-arrow-path" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                    <span>
                        <b class="font-semibold">En re-análisis{{ $state->startedAt ? ' desde el '.$when($state->startedAt) : '' }}</b>{{ $state->startedBy ? ' · '.$state->startedBy : '' }}.
                        Docentes y alumnos siguen viendo la versión {{ $version }}.
                        @if ($full)
                            @if ($changed > 0)
                                {{ $abilities['cancel'] ? 'Cambiaste' : ($changed === 1 ? 'Cambió' : 'Cambiaron') }} <span class="font-mono tabular-nums">{{ $changed }}</span> {{ $changed === 1 ? 'grupo' : 'grupos' }} contra la v{{ $version }} ·
                                <button type="button" class="link link-hover font-semibold underline underline-offset-[3px]" wire:click="openChanges" data-testid="open-changes">Ver</button>
                            @else
                                {{ $abilities['cancel'] ? 'Todavía no cambias nada.' : 'Todavía no cambia nada.' }}
                            @endif
                        @endif
                    </span>
                    @break
                @default
                    <x-icon name="o-lock-closed" class="mt-0.5 h-4 w-4 shrink-0 text-info" aria-hidden="true" />
                    <span>
                        <b class="font-semibold">El re-análisis está en el cierre.</b>
                        La carga está {{ $load->status->label() }}: se publica como la versión {{ $version + 1 }} desde «Cierre de la carga». Docentes y alumnos siguen viendo la versión {{ $version }}.
                    </span>
            @endswitch
        </p>
    </div>

    @if ($state->stage === 'published' && $abilities['start'])
        <div class="flex min-w-0 flex-col items-stretch gap-1.5 max-md:w-full md:items-end">
            @if ($state->isAvailable())
                <x-button
                    label="Iniciar re-análisis"
                    icon="o-arrow-path"
                    class="btn-primary max-md:h-11"
                    wire:click="startReanalysis"
                    spinner="startReanalysis"
                    x-confirm="La carga vuelve a editarse con el validador. Docentes y alumnos siguen viendo la versión {{ $version }} hasta que publiques la {{ $version + 1 }}."
                    data-confirm-title="¿Iniciar el re-análisis de {{ $load->department->name }} · {{ $load->modality->name }}?"
                    data-confirm-button="Iniciar re-análisis"
                    data-confirm-tone="primary"
                    data-testid="start-reanalysis"
                />
            @else
                <x-button label="Iniciar re-análisis" icon="o-arrow-path" class="btn-primary max-md:h-11" :disabled="true" :title="$state->firstBlocker()" aria-describedby="why-start" data-testid="start-reanalysis" />
                <p id="why-start" class="flex items-start gap-1.5 text-[13px] leading-snug text-neutral md:max-w-[44ch] md:justify-end md:text-end"><x-icon name="o-clock" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $state->firstBlocker() }}</p>
            @endif
        </div>
    @elseif ($state->stage === 'reanalysis' && ($abilities['cancel'] || $abilities['close']))
        <div class="flex min-w-0 flex-col items-stretch gap-1.5 max-md:w-full md:items-end">
            <div class="flex gap-2 max-md:flex-col-reverse md:justify-end">
                @if ($abilities['cancel'])
                    @if ($cancelWhy === null)
                        <x-button
                            label="Cancelar re-análisis"
                            icon="o-arrow-uturn-left"
                            class="btn-outline max-md:h-11"
                            wire:click="cancelReanalysis"
                            spinner="cancelReanalysis"
                            x-confirm="No cambiaste nada: la carga vuelve a Publicada · v{{ $version }} sin crear otra versión. Puedes volver a iniciarlo después."
                            data-confirm-title="¿Cancelar el re-análisis?"
                            data-confirm-button="Cancelar re-análisis"
                            data-confirm-tone="warning"
                            data-testid="cancel-reanalysis"
                        />
                    @else
                        <x-button label="Cancelar re-análisis" icon="o-arrow-uturn-left" class="btn-outline max-md:h-11" :disabled="true" :title="$cancelWhy" aria-describedby="why-cancel" data-testid="cancel-reanalysis" />
                    @endif
                @endif
                @if ($abilities['close'] && $closingUrl)
                    <a href="{{ $closingUrl }}" class="btn btn-primary max-md:h-11"
                        x-confirm="Se cierra en «Cierre de la carga», con las mismas guardas del cierre: choques duros, solicitudes de cambio y acuerdos. Después se publica como la versión {{ $version + 1 }}{{ $approval ? ', con el visto bueno de Subdirección' : '' }}."
                        data-confirm-title="Cerrar el re-análisis"
                        data-confirm-button="Ir a Cierre de la carga"
                        data-confirm-tone="primary"
                        data-testid="close-reanalysis">Cerrar re-análisis<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                @endif
            </div>
            @if ($abilities['cancel'] && $cancelWhy !== null)
                <p id="why-cancel" class="flex items-start gap-1.5 text-[13px] leading-snug text-neutral md:max-w-[44ch] md:justify-end md:text-end" data-testid="cancel-why"><x-icon name="o-lock-closed" class="mt-0.5 h-3.5 w-3.5 shrink-0" aria-hidden="true" />{{ $cancelWhy }}</p>
            @endif
        </div>
    @elseif ($state->stage === 'closing' && $abilities['close'] && $closingUrl)
        <a href="{{ $closingUrl }}" class="btn btn-primary max-md:h-11 max-md:w-full" data-testid="go-closing">Ir a Cierre de la carga<x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
    @endif
</section>
