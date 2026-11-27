{{--
    El tablero «Resumen» del alumno (maqueta A «Tablero», DESIGN.md «Resumen del alumno»). `$own` es el alumno viéndose a sí
    mismo (segunda persona, con «Tu acceso» y la tarjeta de identidad con foto); en la ficha del personal (tercera persona) la
    identidad ya va en el encabezado de la página y no hay «Tu acceso». Todo sale de StudentSummaryQuery: ninguna consulta aquí.
--}}
@use('App\Modules\Students\Enums\SubjectStanding')
<div class="space-y-4" data-testid="student-summary">
    @if ($student === null)
        <x-alert title="Todavía no encontramos tus datos escolares." description="Si eres alumno, acude a Servicios Escolares." icon="o-information-circle" class="alert-info" data-testid="summary-empty" />
    @else
        @php
            $go = fn (string $tab): string => $own ? route('students.my-record', ['tab' => $tab]) : '';
            $linkAttrs = fn (string $tab): string => $own ? 'href="'.e($go($tab)).'" wire:navigate' : 'href="#" x-on:click.prevent="$wire.$dispatch(\'student-tab\', { tab: \''.$tab.'\' })"';
        @endphp

        @if ($own)
            <section class="flex items-start gap-4 rounded-box border border-base-300 bg-base-100 p-4 md:items-center md:p-5" aria-label="Tus datos escolares" data-testid="summary-identity">
                @if ($credential['photo_url'] !== null)
                    <img src="{{ $credential['photo_url'] }}" alt="Tu foto" class="aspect-3/4 w-[4.5rem] shrink-0 rounded-md border border-base-300 bg-base-200 object-cover object-top md:w-24" />
                @else
                    <span class="flex aspect-3/4 w-[4.5rem] shrink-0 items-center justify-center rounded-md border border-base-300 bg-base-200 text-2xl font-bold text-neutral md:w-24" aria-hidden="true">{{ $credential['initials'] }}</span>
                @endif
                <div class="min-w-0 flex-1">
                    <h2 class="flex flex-wrap items-center gap-x-3 gap-y-1 text-xl font-bold leading-tight md:text-2xl">
                        {{ $student->name }}
                        <span class="font-mono text-lg font-semibold text-neutral tabular-nums">{{ $student->control_number }}</span>
                        @include('students::status-badge', ['status' => $student->status])
                    </h2>
                    <dl class="mt-3 grid grid-cols-2 gap-x-5 gap-y-3 desktop:grid-cols-[2fr_1.4fr_1fr_1fr_1.4fr]">
                        <div class="col-span-2 desktop:col-span-1"><dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Carrera</dt><dd class="font-medium"><span class="font-mono">{{ $student->career->code }}</span> · {{ $student->career->name }}</dd></div>
                        <div><dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Plan</dt><dd class="font-mono font-medium">{{ $student->studyPlan->code }}</dd></div>
                        <div><dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Campus</dt><dd class="font-medium">{{ $student->campus->name }}</dd></div>
                        <div><dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Semestre</dt><dd class="font-mono font-medium">{{ $student->current_semester ?? '—' }}</dd></div>
                        @if ($summary['specialty'] !== null)
                            <div><dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Especialidad</dt><dd class="font-medium">{{ $summary['specialty'] }}</dd></div>
                        @endif
                    </dl>
                </div>
            </section>
        @endif

        {{-- Cuatro cifras de tablero --}}
        <section class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 bg-base-100 desktop:grid-cols-4" aria-label="{{ $own ? 'Tus cifras' : 'Sus cifras' }}" data-testid="summary-tiles">
            <div class="flex flex-col gap-1.5 px-4 py-3.5 md:px-5">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Créditos aprobados</span>
                <span class="font-mono text-2xl font-semibold tabular-nums" data-testid="tile-credits">{{ $record->creditsApproved }}@if ($record->totalCredits > 0) <span class="text-base font-normal text-neutral">de {{ $record->totalCredits }}</span>@endif</span>
                @if ($record->totalCredits > 0)
                    <progress class="progress progress-success h-1.5 w-full" value="{{ $record->creditsApproved }}" max="{{ $record->totalCredits }}" aria-label="Avance en créditos"></progress>
                    <span class="text-sm text-neutral"><b class="font-mono font-semibold text-base-content">{{ $summary['percent'] }}</b> %@if ($summary['takingCredits'] > 0) · con lo que {{ $own ? 'cursas' : 'cursa' }}, <span class="font-mono">{{ $summary['percentWithTaking'] }}</span> %@endif</span>
                @endif
            </div>
            <div class="flex flex-col gap-1.5 border-base-300 px-4 py-3.5 md:px-5 [&:nth-child(n+2)]:border-l">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Materias acreditadas</span>
                <span class="font-mono text-2xl font-semibold tabular-nums" data-testid="tile-subjects">{{ $record->subjectsApproved }} <span class="text-base font-normal text-neutral">de {{ $record->subjectsInGrid }}</span></span>
                <span class="text-sm text-neutral">De las materias de {{ $own ? 'tu' : 'su' }} retícula</span>
            </div>
            <div class="flex flex-col gap-1.5 border-base-300 px-4 py-3.5 max-desktop:border-t md:px-5 desktop:border-l max-desktop:[&:nth-child(3)]:border-l-0">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Cursando</span>
                <span class="font-mono text-2xl font-semibold tabular-nums" data-testid="tile-taking">{{ $record->taking }}</span>
                <span class="text-sm text-neutral"><span class="font-mono">{{ $summary['takingCredits'] }}</span> créditos @if ($summary['termLabel'] !== null) · {{ $summary['termLabel'] }}@endif</span>
            </div>
            <div class="flex flex-col gap-1.5 border-base-300 border-l px-4 py-3.5 max-desktop:border-t md:px-5">
                <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Puede seleccionarla</span>
                <span class="font-mono text-2xl font-semibold tabular-nums" data-testid="tile-selectable">{{ $record->selectable }}</span>
                <span class="text-sm text-neutral">{{ $own ? 'Ya cumples' : 'Ya cumple' }} lo que piden</span>
            </div>
        </section>

        {{-- Promedios (AC-24): del kárdex, con dos decimales. --}}
        <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-averages-title" data-testid="summary-averages">
            <h2 id="summary-averages-title" class="border-b border-base-300 px-4 py-3 text-base font-bold md:px-5">{{ $own ? 'Tus promedios' : 'Promedios' }}</h2>
            <dl class="grid grid-cols-1 divide-y divide-base-300 md:grid-cols-3 md:divide-x md:divide-y-0">
                @foreach ([
                    ['general', 'Promedio general', $summary['averages']->general, 'De las materias acreditadas'],
                    ['with-failed', 'Promedio con reprobadas', $summary['averages']->withFailed, 'De todas las oportunidades calificadas'],
                    ['last-term', 'Último periodo', $summary['averages']->lastTerm, $summary['lastTermLabel'] ?? 'Sin calificaciones todavía'],
                ] as [$key, $label, $value, $caption])
                    <div class="flex items-baseline justify-between gap-3 px-4 py-3 md:flex-col md:items-start md:gap-1 md:px-5">
                        <dt class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">{{ $label }}</dt>
                        <dd class="flex flex-col md:contents">
                            <span class="font-mono text-2xl font-semibold tabular-nums" data-testid="average-{{ $key }}">{{ $value ?? '—' }}</span>
                            <span class="text-sm text-neutral">{{ $caption }}</span>
                        </dd>
                    </div>
                @endforeach
            </dl>
        </section>

        {{-- Avance por semestre: una mini retícula de estados. --}}
        <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-strip-title" data-testid="summary-strip">
            <div class="flex items-center justify-between gap-3 px-4 py-3 md:px-5">
                <h2 id="summary-strip-title" class="text-base font-bold">Avance por semestre</h2>
                <a {!! $linkAttrs('reticula') !!} class="inline-flex items-center gap-1 font-medium text-primary">Ver retícula <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
            </div>
            @if ($summary['columns'] === [])
                <p class="border-t border-base-300 px-4 py-3 text-neutral md:px-5">{{ $own ? 'Tu plan todavía no tiene materias en la retícula.' : 'Su plan todavía no tiene materias en la retícula.' }}</p>
            @else
                <div class="grid auto-cols-fr grid-flow-col border-t border-base-300">
                    @foreach ($summary['columns'] as $column)
                        <div @class(['flex min-w-0 flex-col items-center gap-2 border-base-300 px-0.5 py-3 md:gap-3 md:px-1 [&:nth-child(n+2)]:border-l', 'bg-base-200' => $column['current'], 'border-dashed!' => $column['specialty']]) data-testid="strip-column">
                            <span class="font-mono text-xs font-semibold md:text-sm"><span class="sr-only">{{ $column['specialty'] ? 'Especialidad' : 'Semestre '.$column['key'] }}</span><span aria-hidden="true">{{ $column['short'] }}</span></span>
                            <span class="flex flex-col items-center gap-1 md:grid md:grid-cols-2" aria-hidden="true">
                                @foreach ($column['cells'] as $cell)
                                    <span @class(['inline-flex size-3.5 items-center justify-center rounded-[3px] border md:size-6 md:rounded', $cell->standing->tileClasses()]) title="{{ $cell->code }} · {{ $cell->standing->label() }}"><x-icon :name="$cell->standing->icon()" class="hidden h-4 w-4 md:block" /></span>
                                @endforeach
                            </span>
                            <span class="font-mono text-[11px] text-neutral md:text-xs"><span class="sr-only">{{ $column['done'] }} de {{ $column['total'] }} acreditadas</span><span aria-hidden="true">{{ $column['done'] }}/{{ $column['total'] }}</span></span>
                            @if ($column['current'])<span class="text-center text-[10px] font-medium uppercase leading-tight tracking-[0.06em] text-neutral max-md:sr-only">{{ $own ? 'Tu semestre' : 'Su semestre' }}</span>@endif
                        </div>
                    @endforeach
                </div>
                <div class="flex flex-wrap items-center gap-x-3 gap-y-2 border-t border-base-300 px-4 py-3 text-[13px] text-neutral md:px-5" data-testid="summary-legend">
                    @foreach ($summary['legend'] as $standing)
                        <span class="inline-flex items-center gap-1">
                            <span @class(['inline-flex h-6 w-6 shrink-0 items-center justify-center rounded border', $standing->tileClasses()]) aria-hidden="true"><x-icon :name="$standing->icon()" class="h-4 w-4" /></span>
                            {{ $standing->label() }}
                        </span>
                    @endforeach
                </div>
            @endif
        </section>

        <div class="grid items-start gap-4 desktop:grid-cols-2">
            {{-- Este periodo --}}
            <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-taking-title" data-testid="summary-taking">
                <div class="flex items-center justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                    <h2 id="summary-taking-title" class="text-base font-bold">Este periodo</h2>
                    @if ($summary['termLabel'] !== null)<span class="text-sm text-neutral">{{ $summary['termLabel'] }}</span>@endif
                </div>
                @forelse ($summary['taking'] as $cell)
                    @php([$primary] = $record->codesFor($cell->subjectId, $cell->code))
                    <div class="flex items-baseline gap-3 border-b border-base-300 px-4 py-2.5 last:border-b-0 md:px-5" data-testid="taking-row">
                        <span class="w-24 shrink-0 font-mono text-sm font-semibold">{{ $primary }}</span>
                        <span class="min-w-0 flex-1 font-medium">{{ $cell->name }}</span>
                        <span class="shrink-0 font-mono text-sm tabular-nums text-neutral">{{ $cell->credits }} cr</span>
                    </div>
                @empty
                    <p class="px-4 py-3 text-neutral md:px-5" data-testid="taking-empty">{{ $own ? 'No estás cursando materias en este momento.' : 'No está cursando materias en este momento.' }}</p>
                @endforelse
                <div class="flex items-center justify-between gap-3 border-t border-base-300 px-4 py-3 text-sm text-neutral md:px-5">
                    <span><b class="font-mono text-base-content">{{ $summary['takingCredits'] }}</b> créditos en <b class="font-mono text-base-content">{{ count($summary['taking']) }}</b> materias</span>
                    <a {!! $linkAttrs('kardex') !!} class="inline-flex items-center gap-1 font-medium text-primary">Ver kárdex <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                </div>
            </section>

            {{-- Atención --}}
            <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-attention-title" data-testid="summary-attention">
                <div class="flex items-center justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                    <h2 id="summary-attention-title" class="text-base font-bold">Atención</h2>
                    @if ($summary['attention'] !== [])
                        <x-core::status-badge tone="warning" :label="count($summary['attention']).' '.(count($summary['attention']) === 1 ? 'materia' : 'materias')" icon="o-exclamation-triangle" />
                    @endif
                </div>
                @forelse ($summary['attention'] as $item)
                    @php([$primary] = $record->codesFor($item['cell']->subjectId, $item['cell']->code))
                    <div class="space-y-1.5 border-b border-base-300 px-4 py-3 last:border-b-0 md:px-5" data-testid="attention-row">
                        <div class="flex flex-wrap items-center justify-between gap-x-3 gap-y-1.5">
                            <span class="min-w-0"><span class="font-mono text-sm font-semibold">{{ $primary }}</span> <span class="font-medium">{{ $item['cell']->name }}</span></span>
                            <span @class(['inline-flex items-center gap-1 rounded-field border px-2 py-0.5 text-sm font-medium', $item['cell']->standing->tileClasses()])><x-icon :name="$item['cell']->standing->icon()" class="h-4 w-4" aria-hidden="true" />{{ $item['cell']->standing->label() }}</span>
                        </div>
                        <p class="text-sm text-neutral">{{ $item['why'] }} Semestre {{ $item['cell']->semester }}.</p>
                    </div>
                @empty
                    <div class="flex items-start gap-3 px-4 py-4 md:px-5" data-testid="attention-empty">
                        <x-icon name="o-check-circle" class="mt-0.5 h-5 w-5 shrink-0 text-success" aria-hidden="true" />
                        <p><b>Nada que atender.</b> <span class="text-neutral">{{ $own ? 'No tienes materias reprobadas.' : 'No tiene materias reprobadas.' }}</span></p>
                    </div>
                @endforelse
            </section>
        </div>

        {{-- Puede seleccionarla: a lo más 6, el resto en la retícula (sin desplazamiento infinito). --}}
        <section class="rounded-box border border-base-300 bg-base-100" aria-labelledby="summary-selectable-title" data-testid="summary-selectable">
            <div class="flex items-center justify-between gap-3 border-b border-base-300 px-4 py-3 md:px-5">
                <h2 id="summary-selectable-title" class="text-base font-bold">Puede seleccionarla</h2>
                <span class="font-mono text-sm text-neutral tabular-nums">{{ $summary['selectableTotal'] }}</span>
            </div>
            @if ($summary['selectable'] === [])
                <p class="px-4 py-3 text-neutral md:px-5" data-testid="selectable-empty">{{ $own ? 'Por ahora no tienes materias por seleccionar.' : 'Por ahora no tiene materias por seleccionar.' }}</p>
            @else
                <ul class="grid md:grid-cols-2">
                    @foreach ($summary['selectable'] as $cell)
                        @php([$primary] = $record->codesFor($cell->subjectId, $cell->code))
                        <li class="flex items-baseline gap-3 border-b border-base-300 px-4 py-2.5 md:px-5 md:[&:nth-child(odd)]:border-r" data-testid="selectable-row">
                            <span class="w-24 shrink-0 font-mono text-sm font-semibold">{{ $primary }}</span>
                            <span class="min-w-0 flex-1 font-medium">{{ $cell->name }}@if ($cell->standing === SubjectStanding::ReprobadaPrioridad) <span class="text-sm text-neutral">· prioridad</span>@endif</span>
                            <span class="shrink-0 font-mono text-sm tabular-nums text-neutral">{{ $cell->semester }}.º</span>
                        </li>
                    @endforeach
                </ul>
                @if ($summary['selectableTotal'] > count($summary['selectable']))
                    <div class="px-4 py-3 md:px-5" data-testid="selectable-more">
                        <a {!! $linkAttrs('reticula') !!} class="inline-flex items-center gap-1 font-medium text-primary">Ver las {{ $summary['selectableTotal'] }} en la retícula <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
                    </div>
                @endif
            @endif
        </section>

        <div @class(['grid items-start gap-4', 'desktop:grid-cols-2' => $own])>
            {{-- Credencial --}}
            <section class="space-y-3 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="summary-credential-title" data-testid="summary-credential">
                <h2 id="summary-credential-title" class="text-base font-bold">{{ $own ? 'Tu credencial' : 'Credencial' }}</h2>
                <div class="flex flex-wrap gap-2">
                    <x-core::status-badge :tone="$credential['current'] ? 'success' : 'neutral'" :label="$credential['current'] ? 'Credencial vigente' : 'Credencial no vigente'" :icon="$credential['current'] ? 'o-identification' : 'o-no-symbol'" />
                    @foreach ($openRequests as $request)
                        <x-core::status-badge tone="info" :label="collect([$request->wants_reposition ? 'Reposición' : null, $request->wants_photo ? 'Foto' : null])->filter()->implode(' y ').' · '.$request->status->label()" icon="o-calendar-days" />
                    @endforeach
                </div>
                @foreach ($openRequests->filter(fn ($request) => $request->scheduled_at !== null) as $request)
                    @php($at = $request->scheduled_at->setTimezone('America/Ciudad_Juarez')->locale('es'))
                    <p class="flex flex-wrap items-center gap-x-2 gap-y-1" data-testid="summary-appointment">
                        <x-icon name="o-calendar-days" class="h-4 w-4 text-neutral" aria-hidden="true" />
                        <span class="font-medium">{{ $at->isoFormat('dddd D [de] MMMM') }} · <span class="font-mono tabular-nums">{{ $at->format('H:i') }}</span></span>
                        @if ($request->place)<span class="text-neutral">{{ $request->place }}</span>@endif
                    </p>
                @endforeach
                @if ($openRequests->isEmpty())
                    <p class="text-neutral">{{ $own ? 'Sin solicitudes abiertas.' : 'Sin solicitudes abiertas.' }}</p>
                @endif
                <a {!! $linkAttrs('credencial') !!} class="inline-flex items-center gap-1 font-medium text-primary">Ver credencial <x-icon name="o-arrow-right" class="h-4 w-4" aria-hidden="true" /></a>
            </section>

            {{-- Tu acceso: solo el alumno. --}}
            @if ($own)
                <section class="space-y-3 rounded-box border border-base-300 bg-base-100 p-4 md:p-5" aria-labelledby="summary-access-title" data-testid="summary-access">
                    <h2 id="summary-access-title" class="text-base font-bold">Tu acceso</h2>
                    <dl class="grid gap-x-6 gap-y-2 sm:grid-cols-[auto_1fr]">
                        <dt class="text-neutral">Usuario</dt>
                        <dd class="font-mono font-medium">{{ $access['username'] }}</dd>
                        <dt class="text-neutral">Correo institucional</dt>
                        <dd class="font-medium [overflow-wrap:anywhere]">{{ $access['email'] ?? 'Sin correo institucional' }}</dd>
                    </dl>
                    <p>{{ $access['usesSiiNip'] ? 'Entras con tu NIP del SII; cámbialo por una contraseña propia.' : 'Entras con tu contraseña de Nexus.' }}</p>
                    <x-button label="Cambiar contraseña" icon="o-key" :link="route('user-password.edit')" class="btn-outline" no-wire-navigate data-testid="summary-change-password" />
                </section>
            @endif
        </div>
    @endif
</div>
