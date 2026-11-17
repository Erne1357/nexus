{{--
    Las materias de una carrera (drawer ancho; variante B): el campus (si la carrera está en varios y la página no filtra
    uno), cuatro cifras (error ponderado con su veredicto, el de R1, las materias dentro del 15 % y el sesgo), la
    dispersión de proyectados contra reales con la banda del 15 % alrededor de la diagonal (en un div y no en un figure,
    porque el x-drawer va dentro de un card de daisyUI, que centra sus figure) y la lista por error: cada
    materia con su barra del error con signo (de menos a la izquierda, de más a la derecha; las marcas son el 15 %), sus
    elegibles, sus grupos sugeridos contra los abiertos («—» mientras no hay historial de grupos) y su método si no es la
    fórmula. Los filtros de la lista (todas, fuera del 15 %, sin historia) y la búsqueda son del navegador.
    `$subjects` (CareerSubjects), `$current` (ForecastEvaluationItem), `$department` (el filtro de la página) y `$campus`.
--}}
@php
    $career = $subjects->career;
    $metrics = $subjects->metrics;
    $totals = $subjects->totals();
    $counts = $subjects->counts();
    $scatter = $subjects->scatter();
    $where = $subjects->campus?->name
        ?? ($subjects->campuses->count() > 1 ? $subjects->campuses->pluck('name')->join(', ', ' y ') : ($subjects->campuses->first()->name ?? $current->campus));
    $multi = $subjects->campus === null && $subjects->campuses->count() > 1;
    $meta = \App\Modules\AcademicLoad\Rules\ForecastAccuracy::STRONG_THRESHOLD;
    $scale = 0.6;
@endphp
<div class="flex h-dvh flex-col" data-testid="subjects-panel">
    @include('academic-load::forecast-accuracy.drawer-header', [
        'id' => 'subjects-title',
        'title' => '<span class="me-1.5 font-mono">'.e($career->code).'</span>'.e($career->name),
        'subtitle' => collect([$current->term, $current->modality, $where, $department ? "Materias de {$department->code}" : $career->department?->name])->filter()->join(' · '),
    ])

    <div class="@container flex-1 space-y-[18px] overflow-y-auto px-5 py-4 max-md:px-3.5">
        @if (blank($campus) && $subjects->campuses->count() > 1)
            <div role="group" aria-label="Campus" class="join grid w-full @2xl:inline-grid @2xl:w-auto" style="grid-template-columns: repeat({{ $subjects->campuses->count() + 1 }}, minmax(0, 1fr))" data-testid="subjects-campus">
                @foreach ([['code' => '', 'name' => $subjects->campuses->count() === 2 ? 'Los dos' : 'Todos'], ...$subjects->campuses->map(fn ($option) => ['code' => $option->code, 'name' => $option->name])->all()] as $option)
                    @php
                        $pressed = ($subjects->campus?->code ?? '') === $option['code'];
                    @endphp
                    <button type="button" wire:click="$set('subjectCampus', '{{ $option['code'] }}')" aria-pressed="{{ $pressed ? 'true' : 'false' }}"
                        @class(['btn join-item min-w-0 whitespace-normal px-3 leading-tight max-md:h-auto max-md:min-h-11', 'btn-primary' => $pressed])>{{ $option['name'] }}</button>
                @endforeach
            </div>
        @endif

        <dl class="grid grid-cols-2 overflow-hidden rounded-box border border-base-300 @2xl:grid-cols-4" data-testid="subjects-facts">
            <div class="min-w-0 border-base-300 px-4 py-3">
                <dt class="text-[13px] text-neutral">Error ponderado</dt>
                <dd class="mt-0.5 text-[22px] font-semibold"><x-academic-load::percent :value="$metrics->weightedError" /></dd>
                <x-core::status-badge :tone="$metrics->confidence->tone()" :label="$metrics->confidence->label()" :icon="$metrics->confidence->icon()" class="mt-1" />
            </div>
            <div class="min-w-0 border-s border-base-300 px-4 py-3">
                <dt class="text-[13px] text-neutral">Con la regla de R1</dt>
                <dd class="mt-0.5 text-[22px] font-semibold"><x-academic-load::percent :value="$metrics->baselineError" /></dd>
                <p class="mt-1 text-[12.5px] text-neutral">esperados = elegibles</p>
            </div>
            <div class="min-w-0 border-base-300 px-4 py-3 @max-2xl:border-t @2xl:border-s">
                <dt class="text-[13px] text-neutral">Dentro del 15&nbsp;%</dt>
                <dd class="mt-0.5 font-mono text-[22px] font-semibold tabular-nums">{{ $totals['within'] }}<span class="ms-1 font-sans text-sm font-normal text-neutral">de {{ $totals['withStudents'] }}</span></dd>
                <p class="mt-1 text-[12.5px] text-neutral">materias con alumnos</p>
            </div>
            <div class="min-w-0 border-s border-base-300 px-4 py-3 @max-2xl:border-t">
                <dt class="text-[13px] text-neutral">Sesgo</dt>
                <dd class="mt-0.5 font-mono text-[22px] font-semibold tabular-nums">@include('academic-load::forecast-accuracy.bias', ['bias' => $metrics->bias])</dd>
                <p class="mt-1 text-[12.5px] text-neutral">alumnos por materia{{ round($metrics->bias, 1) == 0 ? '' : ($metrics->bias > 0 ? ', sobreestima' : ', subestima') }}</p>
            </div>
        </dl>

        <section class="grid gap-2.5" aria-labelledby="scatter-title">
            <div class="flex flex-wrap items-baseline justify-between gap-2">
                <h3 id="scatter-title" class="font-semibold">Proyectados contra reales</h3>
                <span class="text-[12.5px] text-neutral">{{ count($scatter['points']) }} {{ count($scatter['points']) === 1 ? 'materia' : 'materias' }}</span>
            </div>
            <div class="grid gap-2" data-testid="subjects-scatter">
                <div class="relative ms-9 me-2.5 mt-6 mb-[30px] h-56 @xl:h-72" role="img" aria-label="Cada punto es una materia: a la derecha, más alumnos reales; arriba, más proyectados. Sobre la diagonal, la proyección acertó.">
                    @foreach ($scatter['ticks'] as $tick)
                        @php
                            $at = round($tick / $scatter['top'] * 100, 2);
                        @endphp
                        <span class="absolute inset-x-0 h-px bg-base-300" style="bottom: {{ $at }}%" aria-hidden="true"></span>
                        <span class="absolute -left-2 -translate-x-full translate-y-1/2 font-mono text-[11.5px] text-neutral" style="bottom: {{ $at }}%" aria-hidden="true">{{ $tick }}</span>
                        <span @class(['absolute top-full mt-1.5 font-mono text-[11.5px] text-neutral', '-translate-x-1/2' => ! $loop->last, '-translate-x-full' => $loop->last, '@max-md:hidden' => $loop->odd && ! $loop->last && ! $loop->first]) style="left: {{ $at }}%" aria-hidden="true">{{ $tick }}</span>
                    @endforeach
                    <svg class="absolute inset-0 h-full w-full overflow-visible" viewBox="0 0 100 100" preserveAspectRatio="none" aria-hidden="true">
                        <polygon points="{{ $scatter['band'] }}" class="fill-success/15" />
                        <line x1="0" y1="100" x2="100" y2="0" class="stroke-success" stroke-width="1.5" vector-effect="non-scaling-stroke" />
                    </svg>
                    @foreach ($scatter['points'] as $point)
                        <span @class([
                            'absolute -translate-x-1/2 translate-y-1/2 rounded-full',
                            'h-[9px] w-[9px] border-2 border-warning bg-base-100' => $point['out'],
                            'h-[7px] w-[7px] bg-success ring-2 ring-base-100' => ! $point['out'],
                        ]) style="left: {{ $point['x'] }}%; bottom: {{ $point['y'] }}%" title="{{ $point['label'] }}"></span>
                    @endforeach
                    <span class="absolute -top-6 -left-9 flex items-center gap-1 text-[11.5px] font-medium text-neutral" aria-hidden="true">Proyectados<x-icon name="o-arrow-up" class="h-3 w-3" /></span>
                    <span class="absolute right-0 bottom-1.5 flex items-center gap-1 text-[11.5px] font-medium text-neutral" aria-hidden="true">Reales<x-icon name="o-arrow-right" class="h-3 w-3" /></span>
                </div>
                <p class="flex flex-wrap items-center gap-x-[18px] gap-y-1.5 text-[12.5px] text-neutral">
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full bg-success" aria-hidden="true"></span>Dentro del 15&nbsp;%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-2.5 rounded-full border-2 border-warning bg-base-100" aria-hidden="true"></span>Fuera del 15&nbsp;%</span>
                    <span class="inline-flex items-center gap-1.5"><span class="inline-block h-2.5 w-4 rounded-sm border border-success bg-success/20" aria-hidden="true"></span>Banda del 15&nbsp;% alrededor de la diagonal</span>
                </p>
            </div>
        </section>

        <section class="grid gap-2.5" aria-labelledby="subjects-list-title"
            x-data="{
                filter: 'all',
                q: '',
                fold(text) { return text.normalize('NFD').replace(/[̀-ͯ]/g, '').toLowerCase().trim() },
                shows(item) {
                    const filter = this.filter === 'all' || item.dataset[this.filter] === '1'
                    return filter && (this.q === '' || item.dataset.search.includes(this.fold(this.q)))
                },
                get empty() { return ! [...this.$refs.list.children].some((item) => this.shows(item)) },
            }">
            <h3 id="subjects-list-title" class="font-semibold">Materias por error</h3>
            <div class="flex flex-wrap items-center gap-2.5">
                <div role="group" aria-label="Materias" class="join grid w-full grid-cols-3 @xl:inline-grid @xl:w-auto">
                    @foreach (['all' => ['Todas', $counts['all']], 'out' => ['Fuera del 15 %', $counts['out']], 'noHistory' => ['Sin historia', $counts['noHistory']]] as $value => [$label, $count])
                        <button type="button" @click="filter = '{{ $value }}'" :aria-pressed="filter === '{{ $value }}'" :class="filter === '{{ $value }}' && 'btn-primary'"
                            class="btn join-item h-auto min-h-10 min-w-0 gap-1.5 whitespace-normal px-3 py-1 leading-tight max-md:min-h-11 @max-xl:flex-col @max-xl:gap-0"
                            aria-pressed="{{ $value === 'all' ? 'true' : 'false' }}">
                            <span>{{ $label }}</span><span class="font-mono text-xs tabular-nums">{{ $count }}</span>
                        </button>
                    @endforeach
                </div>
                <label class="input w-full grow max-md:h-11 @xl:w-auto @xl:min-w-48">
                    <x-icon name="o-magnifying-glass" class="h-4 w-4 text-neutral" aria-hidden="true" />
                    <input type="search" x-model="q" placeholder="Clave o materia" aria-label="Buscar materia" autocomplete="off" />
                </label>
            </div>

            <ul x-ref="list" class="divide-y divide-base-300 rounded-box border border-base-300" data-testid="subjects-list">
                @foreach ($subjects->subjects as $subject)
                    @php
                        $error = $subject['error'];
                        $width = $error === null ? 0 : round(min(abs($error), $scale) / $scale * 50, 2);
                        $search = \Illuminate\Support\Str::lower(\Illuminate\Support\Str::ascii("{$subject['code']} {$subject['name']}"));
                    @endphp
                    <li x-show="shows($el)" data-out="{{ $subject['out'] ? '1' : '0' }}" data-no-history="{{ $subject['noHistory'] ? '1' : '0' }}" data-search="{{ $search }}"
                        class="grid grid-cols-[minmax(0,1fr)_9rem_8.5rem] items-center gap-x-4 gap-y-1.5 px-3.5 py-2.5 @max-xl:grid-cols-[minmax(0,1fr)_auto]"
                        data-testid="subject-row" data-subject="{{ $subject['code'] }}">
                        <div class="flex min-w-0 flex-wrap items-center gap-x-2 gap-y-0.5 @max-xl:col-span-2">
                            <span class="font-mono text-[13.5px] font-semibold">{{ $subject['code'] }}</span>
                            @if ($multi)
                                <x-core::campus-tag :campus="$subject['campus']" />
                            @endif
                            @if ($subject['methodLabel'])
                                <x-core::status-badge :tone="$subject['noHistory'] ? 'info' : 'neutral'" :label="$subject['methodLabel']" :icon="$subject['noHistory'] ? 'o-information-circle' : 'o-minus-circle'" class="h-5.5 text-[12px] [&_svg]:h-3 [&_svg]:w-3" />
                            @endif
                            <span class="basis-full font-semibold leading-snug">{{ $subject['name'] }}</span>
                            <span class="basis-full text-[12.5px] text-neutral">{{ $subject['department'] ? $subject['department'].' · ' : '' }}{{ number_format($subject['eligible']) }} elegibles · grupos {{ $subject['suggestedGroups'] }} → {{ $subject['actualGroups'] ?? '—' }}</span>
                        </div>
                        <div class="relative h-[18px]" aria-hidden="true">
                            <span class="absolute inset-x-0 top-1/2 h-px bg-base-300"></span>
                            <span class="absolute inset-y-0 left-1/2 w-px bg-base-content/55"></span>
                            <span class="absolute inset-y-[3px] w-px bg-success" style="left: {{ round(50 - 50 * $meta / $scale, 2) }}%"></span>
                            <span class="absolute inset-y-[3px] w-px bg-success" style="left: {{ round(50 + 50 * $meta / $scale, 2) }}%"></span>
                            @if ($error === null)
                                @if ($subject['projected'] > 0)
                                    <span class="absolute left-1/2 top-0 z-[1] -translate-x-1/2 whitespace-nowrap bg-base-100 px-1.5 text-[11.5px] text-warning">sin alumnos</span>
                                @endif
                            @else
                                <span @class([
                                    'absolute top-[5px] h-2',
                                    'left-1/2 rounded-e-[3px]' => $error >= 0,
                                    'right-1/2 rounded-s-[3px]' => $error < 0,
                                    'bg-warning' => $subject['out'],
                                    'bg-base-content/60' => ! $subject['out'],
                                ]) style="width: {{ $width }}%"></span>
                            @endif
                        </div>
                        <div class="grid justify-items-end gap-0.5 text-[13.5px]">
                            <span class="font-mono tabular-nums"><span class="font-semibold">{{ number_format($subject['projected']) }}</span> <span class="font-sans text-neutral">de</span> {{ number_format($subject['actual']) }}</span>
                            <span @class(['inline-flex items-center gap-1 whitespace-nowrap', 'text-warning' => $subject['out'], 'text-neutral' => ! $subject['counts']])>
                                @if ($subject['out'])
                                    <x-icon name="o-exclamation-triangle" class="h-3.5 w-3.5" aria-hidden="true" />
                                @endif
                                @if ($error !== null)
                                    <x-academic-load::percent :value="$error" signed />
                                @elseif ($subject['projected'] > 0)
                                    <span class="font-mono tabular-nums">+{{ number_format($subject['projected']) }}</span>
                                @else
                                    <span>—</span>
                                @endif
                            </span>
                        </div>
                    </li>
                @endforeach
            </ul>
            <p x-show="empty" x-cloak class="px-4 py-6 text-center text-neutral">Ninguna materia con ese filtro.</p>
            <p class="text-[12.5px] text-neutral">Barra: de menos a la izquierda, de más a la derecha del cero; las marcas son el 15&nbsp;%.</p>
        </section>
    </div>

    <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 px-5 py-3">
        <span class="me-auto text-[12.5px] text-neutral max-md:w-full">Reales: quienes tienen la materia en el kárdex de {{ $current->term }}, aprobada, reprobada o cursando.</span>
        <x-button label="Cerrar" class="max-md:w-full" @click="close()" />
    </footer>
</div>
