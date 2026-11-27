{{--
    Precisión de la proyección (tarea 5.9c; DESIGN.md, «Precisión de la proyección»: variante B «Contra la meta», elegida
    por el usuario el 17 de noviembre de 2026; maqueta en .impeccable/mocks/precision/b.html). La evaluación elegida en
    una franja con «Evaluaciones» y «Evaluar un periodo» (los dos en drawer); lista, el bloque de todo el Tec con su regla,
    los filtros (departamento de la materia, campus y la vista) y las carreras contra la meta agrupadas por veredicto, o la
    tabla; corriendo, el avance y las carreras que ya terminaron (la página consulta cada 2 s; cada 10 s si la que corre
    es otra, y nunca por una que se dio por perdida: ForecastEvaluationsQuery::pollEvery); fallida, su error; sin
    ninguna, el estado vacío. Tocar una carrera abre sus materias en un drawer.
--}}
<div @if ($pollEvery === '2s') wire:poll.2s @elseif ($pollEvery === '10s') wire:poll.10s @endif class="space-y-4">
    <x-header title="Precisión de la proyección" size="text-[1.625rem] md:text-3xl" weight="font-bold" class="mb-4!" use-h1 separator progress-indicator>
        <x-slot:subtitle class="max-w-[80ch] text-sm text-neutral!">
            Compara lo que Nexus habría proyectado antes de un periodo con los alumnos que de verdad llevaron cada materia, y con la regla de R1.
        </x-slot:subtitle>
    </x-header>

    @if ($current === null)
        <section class="grid max-w-[44rem] justify-items-start gap-2.5 rounded-box border border-base-300 bg-base-100 px-8 py-10 max-md:px-[18px] max-md:py-7" data-testid="empty-state">
            <span class="grid h-11 w-11 place-items-center rounded-box border border-base-300 bg-base-200 text-neutral">
                <x-icon name="o-scale" class="h-6 w-6" aria-hidden="true" />
            </span>
            <h2 class="text-xl font-bold">Todavía no hay evaluaciones</h2>
            <p class="max-w-[60ch] text-neutral">Elige un periodo con kárdex: Nexus calcula la proyección como si corriera antes de ese periodo y aquí ves, carrera por carrera, de qué lado de la meta del 15&nbsp;% quedó.</p>
            @include('academic-load::forecast-accuracy.evaluate-button', ['canRun' => $canRun, 'class' => 'mt-1.5'])
        </section>
    @else
        @include('academic-load::forecast-accuracy.strip', ['current' => $current, 'items' => $items, 'canRun' => $canRun])

        @if ($current->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Failed)
            <x-alert icon="o-x-circle" class="alert-error alert-soft items-start" data-testid="failed-alert">
                <div class="grid gap-0.5">
                    <p class="font-semibold">La evaluación no terminó.</p>
                    <p>{{ $current->error }}</p>
                </div>
                <x-slot:actions>
                    @if ($canRun)
                        <x-button label="Volver a evaluar" icon="o-arrow-path" class="btn-sm" wire:click="openEvaluate('{{ $current->termId }}', '{{ $current->modalityCode }}', '{{ $current->campusCode }}')" data-testid="evaluate-again" />
                    @endif
                </x-slot:actions>
            </x-alert>
        @elseif ($current->status->isPending())
            @include('academic-load::forecast-accuracy.running', ['current' => $current, 'report' => $report, 'items' => $items])
        @else
            @include('academic-load::forecast-accuracy.summary', ['current' => $current, 'report' => $report])

            <div class="flex flex-wrap items-center gap-x-2.5 gap-y-3" data-testid="accuracy-toolbar">
                @if ($report->departments->isNotEmpty())
                    <div class="w-full md:w-60">
                        <x-select wire:model.live="department" aria-label="Departamento de la materia" placeholder="Departamento" placeholder-value=""
                            :options="$report->departments->map(fn ($department) => ['id' => $department->code, 'name' => $department->code.' · '.$department->name])->all()"
                            class="w-full max-md:h-11" data-testid="department-filter" />
                    </div>
                @endif
                <x-core::campus-filter :options="$report->campuses" :selected="$report->campus?->code" all :counts="$report->campusCounts" :total="count($report->careers)" class="max-md:w-full" />
                <div class="w-full md:ms-auto md:w-auto">
                    <div role="group" aria-label="Vista" class="join w-full md:w-auto max-md:grid max-md:grid-cols-2" data-testid="view-toggle">
                        @foreach (['grafica' => ['Gráfica', 'o-chart-bar'], 'tabla' => ['Tabla', 'o-table-cells']] as $value => [$label, $icon])
                            <button type="button" wire:click="$set('view', '{{ $value }}')" aria-pressed="{{ $view === $value ? 'true' : 'false' }}" @class(['btn join-item gap-2 max-md:h-11', 'btn-primary' => $view === $value])>
                                <x-icon :name="$icon" class="h-4 w-4" aria-hidden="true" />{{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            </div>

            @if ($report->careers === [])
                <section class="rounded-box border border-base-300 bg-base-100 px-4 py-6 text-center text-neutral">Ninguna carrera con esos filtros.</section>
            @elseif ($view === 'tabla')
                @include('academic-load::forecast-accuracy.table', ['report' => $report])
            @else
                @include('academic-load::forecast-accuracy.chart', ['report' => $report])
            @endif
        @endif
    @endif

    <x-drawer wire:model.live="showEvaluations" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="evaluations-title" data-testid="evaluations-drawer">
        @if ($showEvaluations)
            @include('academic-load::forecast-accuracy.evaluations-drawer', ['items' => $items, 'current' => $current, 'canRun' => $canRun])
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showEvaluate" right close-on-escape class="w-full max-w-md p-0!" role="dialog" aria-modal="true" aria-labelledby="evaluate-title" data-testid="evaluate-drawer">
        @if ($form)
            @include('academic-load::forecast-accuracy.evaluate-drawer', ['form' => $form])
        @endif
    </x-drawer>

    <x-drawer wire:model.live="showSubjects" right close-on-escape class="w-full max-w-[860px] p-0!" role="dialog" aria-modal="true" aria-labelledby="subjects-title" data-testid="subjects-drawer">
        @if ($subjects)
            @include('academic-load::forecast-accuracy.subjects-drawer', ['subjects' => $subjects, 'current' => $current, 'department' => $report?->department])
        @endif
    </x-drawer>
</div>
