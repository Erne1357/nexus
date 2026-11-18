{{--
    Re-análisis de la carga (tarea 8.2, pantalla, #602; opción B «Por materia» de .impeccable/mocks/reanalisis/, elegida
    por el usuario el 17 de noviembre de 2026). El título con el departamento, la modalidad y el periodo; a la derecha, la
    insignia del estado de la carga, el select «Carga» a quien ve varias y el de «Modalidad» con más de una.

    - Publicada: el estado con la línea del ciclo e «Iniciar re-análisis»; debajo, lo que pide el re-análisis (RA-02) y
      qué pasa al iniciarlo. Al coordinador, solo el aviso de que la carga está publicada.
    - En re-análisis: el estado con sus acciones; al coordinador, la nota de que los ajustes los hace la jefatura (RA-07);
      los filtros de clase con sus cifras, la búsqueda, la carrera, el campus con sus cifras y la leyenda; y los bloques
      por materia y campus (reanalysis.block). «Lo que ya cambiaste» y las sugerencias (8.3) van en drawers.
--}}
<div class="space-y-4" @if ($state?->stage === 'published' && array_keys($state->blockers) === ['forecast']) wire:poll.30s @endif>
    <x-header title="Re-análisis de la carga" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-0!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="text-sm text-neutral!">
            @if ($load)
                <span class="flex flex-wrap items-baseline gap-x-2 gap-y-0.5" data-testid="reanalysis-meta">
                    <span class="font-mono text-[15px] font-semibold text-base-content">{{ $load->department->code }}</span>
                    <span class="font-semibold text-base-content">{{ $load->department->name }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $load->modality->name }}</span>
                    <span aria-hidden="true">·</span>
                    <span>{{ $load->term->name }} <span class="font-mono text-[13px]">({{ $load->term->code }})</span></span>
                    @if ($careerIds !== null)
                        <span aria-hidden="true">·</span>
                        <span data-testid="reanalysis-scope">Solo {{ $careerCodes }}</span>
                    @endif
                </span>
            @else
                Compara la demanda con las calificaciones del semestre actual contra la carga publicada.
            @endif
        </x-slot:subtitle>
        <x-slot:actions>
            <div class="flex flex-wrap items-end gap-3 max-md:w-full">
                @if ($load)
                    <x-core::status-badge
                        :tone="$load->status->tone()"
                        :label="$load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Published ? 'Publicada · v'.$load->current_version : $load->status->label()"
                        :icon="$load->status === \App\Modules\AcademicLoad\Enums\LoadStatus::Reanalysis ? 'o-arrow-path' : null"
                        @class(['mb-2.5' => count($loads) > 1 || $modalityOptions->count() > 1])
                        data-testid="load-status"
                    />
                @endif
                @if (count($loads) > 1)
                    <div class="w-full md:w-80">
                        <x-select label="Carga" wire:model.live="department" :options="$loads" class="max-md:h-11" data-testid="reanalysis-load" />
                    </div>
                @endif
                <x-core::modality-scope :options="$modalityOptions" :selected="$shownModality?->code" class="w-full md:w-56" />
            </div>
        </x-slot:actions>
    </x-header>

    @if ($load === null)
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="reanalysis-empty">
            <x-icon name="o-arrow-path" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">{{ $message }}</p>
        </div>
    @elseif ($state->stage === 'other')
        <div class="rounded-box border border-base-300 bg-base-100 px-5 py-8 text-center" data-testid="reanalysis-empty">
            <x-icon name="o-arrow-path" class="mx-auto mb-2 h-8 w-8 text-neutral" aria-hidden="true" />
            <p class="font-semibold">La carga de {{ $load->department->name }} está en {{ $load->status->label() }}: se re-analiza cuando vuelva a publicarse.</p>
        </div>
    @elseif ($state->stage === 'published' && $careerIds !== null)
        <section class="flex items-start gap-3.5 rounded-box border border-base-300 bg-base-100 px-5 py-5" data-testid="reanalysis-waiting">
            <x-icon name="o-clock" class="h-7 w-7 shrink-0 text-neutral" aria-hidden="true" />
            <div>
                <h2 class="text-base font-bold">La carga de {{ $load->department->name }} está publicada</h2>
                <p class="mt-1 max-w-[70ch] text-neutral">Cuando la jefatura inicie el re-análisis con las calificaciones de {{ $state->gradesTerm }}, aquí verás cómo cambió la demanda de {{ $careerCodes }} en sus materias.</p>
            </div>
        </section>
    @else
        @include('scheduling::reanalysis.state', ['state' => $state, 'board' => $board, 'abilities' => $abilities, 'closingUrl' => $closingUrl, 'full' => $careerIds === null])

        @if ($state->stage === 'published')
            <div class="grid gap-4 md:grid-cols-2">
                <section class="grid content-start gap-3.5 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" aria-labelledby="checks-title" data-testid="reanalysis-checks">
                    <h2 id="checks-title" class="text-base font-bold">Lo que pide el re-análisis</h2>
                    <ul class="grid gap-3">
                        @foreach ($state->checks as $check)
                            <li class="grid grid-cols-[1.25rem_minmax(0,1fr)] items-start gap-2.5" data-check="{{ $check['key'] }}" data-state="{{ $check['state'] }}">
                                <x-icon :name="['ok' => 'o-check-circle', 'wait' => 'o-clock', 'pending' => 'o-minus-circle'][$check['state']]" @class(['mt-px h-5 w-5', 'text-success' => $check['state'] === 'ok', 'text-warning' => $check['state'] === 'wait', 'text-neutral' => $check['state'] === 'pending']) aria-hidden="true" />
                                <div>
                                    <b class="block font-semibold">{{ $check['title'] }}<span class="sr-only">: {{ ['ok' => 'listo', 'wait' => 'falta', 'pending' => 'sin revisar'][$check['state']] }}</span></b>
                                    <span @class(['block text-[13.5px] leading-snug', 'text-neutral' => $check['state'] !== 'wait'])>{{ $check['detail'] }}</span>
                                </div>
                            </li>
                        @endforeach
                    </ul>
                </section>
                <section class="grid content-start gap-3.5 rounded-box border border-base-300 bg-base-100 px-4 py-4 md:px-5" aria-labelledby="start-title">
                    <h2 id="start-title" class="text-base font-bold">Al iniciar</h2>
                    <ul class="grid gap-2">
                        @foreach ([
                            ['o-pencil-square', 'La carga vuelve a editarse con el validador: horarios, cupos, salones y docentes.'],
                            ['o-eye', 'Docentes y alumnos siguen viendo la versión '.$load->current_version.' hasta que se publique la '.($load->current_version + 1).'.'],
                            ['o-academic-cap', 'Cada coordinador ve cómo cambió la demanda de su carrera; los ajustes los hace la jefatura.'],
                        ] as [$icon, $text])
                            <li class="grid grid-cols-[1.125rem_minmax(0,1fr)] items-start gap-2.5"><x-icon :name="$icon" class="mt-0.5 h-4 w-4 text-neutral" aria-hidden="true" /><span>{{ $text }}</span></li>
                        @endforeach
                    </ul>
                    @if ($load->published_at)
                        <p class="text-[13px] text-neutral">Vigente: versión {{ $load->current_version }}, publicada el {{ $load->published_at->timezone(config('app.timezone'))->translatedFormat('j M Y') }}@if ($state->publishedGroups !== null) · <span class="font-mono tabular-nums">{{ number_format($state->publishedGroups) }}</span> {{ $state->publishedGroups === 1 ? 'grupo' : 'grupos' }}@endif.</p>
                    @endif
                </section>
            </div>
        @elseif ($board !== null)
            @if ($careerIds !== null)
                <x-alert icon="o-information-circle" class="alert-info alert-soft items-start" role="note" data-testid="coordinator-note">
                    <div class="grid gap-0.5">
                        <b class="font-semibold">Solo consulta: los ajustes los hace la jefatura de {{ $load->department->name }}.</b>
                        <span>{{ $state->startedBy ?? 'La jefatura' }} abre, cierra y amplía grupos. Aquí ves cómo cambió la demanda de {{ $careerCodes }} en las materias de esta carga, contra la versión {{ $load->current_version }} publicada.</span>
                    </div>
                </x-alert>
            @endif

            <section class="space-y-3" aria-label="Diferencia de demanda por materia">
                <div class="grid grid-cols-2 gap-2 md:flex md:flex-wrap" role="group" aria-label="Filtrar por clase" data-testid="class-filters">
                    @foreach ($board->chips as $chip)
                        @php($pressed = $chip['key'] === 'review' ? $review : $class === $chip['key'])
                        <button type="button"
                            @if ($chip['key'] === 'review') wire:click="$toggle('review')" @else wire:click="toggleClass('{{ $chip['key'] }}')" @endif
                            aria-pressed="{{ $pressed ? 'true' : 'false' }}"
                            @class(['btn h-11 justify-start gap-2 px-2.5 font-medium md:h-10 md:px-3.5', 'btn-primary' => $pressed, 'border-base-content/15 bg-base-100' => ! $pressed])
                            data-testid="chip-{{ $chip['key'] }}">
                            <x-icon :name="$chip['icon']" @class(['h-4 w-4 shrink-0', 'text-error' => ! $pressed && $chip['tone'] === 'error', 'text-warning' => ! $pressed && $chip['tone'] === 'warning', 'text-success' => ! $pressed && $chip['tone'] === 'success', 'text-neutral' => ! $pressed && $chip['tone'] === 'neutral']) aria-hidden="true" />
                            <span class="truncate">{{ $chip['label'] }}</span>
                            <span class="ms-auto font-mono text-[15px] font-semibold tabular-nums md:ms-0">{{ $chip['count'] }}</span>
                        </button>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-center gap-x-2.5 gap-y-2.5" data-testid="reanalysis-toolbar">
                    <div class="w-full md:w-56">
                        <x-input icon="o-magnifying-glass" placeholder="Materia o clave" aria-label="Buscar materia o clave" wire:model.live.debounce.300ms="search" clearable class="max-md:h-11" data-testid="reanalysis-search" />
                    </div>
                    @if (count($board->careers) > 1)
                        <div class="w-full md:w-48">
                            <x-select wire:model.live="career" aria-label="Carrera" placeholder="Carrera" placeholder-value="" :options="$board->careers" class="max-md:h-11" data-testid="reanalysis-career" />
                        </div>
                    @endif
                    <x-core::campus-filter :options="$campusOptions" :selected="$shownCampus?->code" all :counts="$board->campusCounts" :total="$board->campusTotal" class="max-md:w-full" />
                    @if ($class !== '' || $review || $career !== '' || $search !== '' || $shownCampus !== null)
                        <x-button label="Quitar filtros" icon="o-x-mark" class="btn-ghost btn-sm max-md:h-11" wire:click="clearFilters" data-testid="clear-filters" />
                    @endif
                    <span class="flex flex-wrap items-center gap-1.5 text-[12.5px] text-neutral md:ms-auto" aria-hidden="true">
                        <span class="inline-block h-2.5 w-[22px] rounded-sm bg-primary/25"></span>Cupos v{{ $load->current_version }}
                        <span class="ms-2 inline-block h-3.5 w-[22px] border border-success/50 bg-[image:repeating-linear-gradient(135deg,color-mix(in_oklab,var(--color-success)_28%,transparent)_0_3px,transparent_3px_6px)]"></span>±{{ $board->threshold }} %
                        <span class="ms-2 inline-block h-4 w-[3px] rounded-sm bg-base-content"></span>Esperados
                    </span>
                </div>

                @if ($board->blocks === [])
                    <p class="rounded-box border border-base-300 bg-base-100 px-4 py-6 text-center text-neutral" data-testid="reanalysis-no-blocks">
                        {{ $board->rowCount === 0 ? 'La proyección no tiene materias de esta carga'.($careerIds === null ? '' : ' para tu carrera').' y la versión publicada no las ofrece.' : 'Ninguna materia con estos filtros.' }}
                    </p>
                @else
                    <ul class="grid rounded-box border border-base-300 bg-base-100" data-testid="reanalysis-blocks">
                        @foreach ($board->blocks as $block)
                            @include('scheduling::reanalysis.block', ['block' => $block, 'board' => $board, 'withSuggestions' => $careerIds === null, 'mine' => $abilities['cancel']])
                        @endforeach
                    </ul>
                    <p class="text-[13px] text-neutral">{{ count($board->blocks) }} {{ count($board->blocks) === 1 ? 'materia' : 'materias' }} en {{ $board->campusesShown() }} {{ $board->campusesShown() === 1 ? 'campus' : 'campus' }} · primero las que tienen faltantes, después las que tienen sobrantes.</p>
                @endif
            </section>
        @endif

        @if ($careerIds === null && $board !== null)
            <x-drawer wire:model.live="showChanges" right close-on-escape class="w-full max-w-lg p-0!" role="dialog" aria-modal="true" aria-labelledby="changes-title" data-testid="changes-drawer">
                @if ($showChanges)
                    @include('scheduling::reanalysis.changes', ['entries' => $board->changes ?? [], 'load' => $load, 'campusOptions' => $campusOptions, 'mine' => $abilities['cancel']])
                @endif
            </x-drawer>
            <livewire:scheduling::components.reanalysis-suggestions :load-id="$load->id" wire:key="reanalysis-suggestions-{{ $load->id }}" />
        @endif
    @endif
</div>
