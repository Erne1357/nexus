{{--
    El drawer «Evaluaciones»: todas, la más reciente primero (ForecastEvaluationsQuery::LIST_LIMIT). Cada una con su periodo
    y alcance; lista, su veredicto, su error y el de R1; en curso, su estado y su avance; fallida, la primera oración de
    su error; y cuándo y quién la pidió. Tocar una la elige. Al pie, «Evaluar un periodo». `$items`, `$current` y
    `$canRun`.
--}}
<div class="flex h-dvh flex-col" data-testid="evaluations-panel">
    @include('academic-load::forecast-accuracy.drawer-header', ['id' => 'evaluations-title', 'title' => 'Evaluaciones', 'subtitle' => ($items->count() === 1 ? '1 evaluación' : $items->count().' evaluaciones').', la más reciente primero'])

    <div class="flex-1 overflow-y-auto px-5 py-4">
        <ol class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300">
            @foreach ($items as $item)
                @php($chosen = $current?->id === $item->id)
                <li wire:key="evaluation-{{ $item->id }}">
                    <button type="button" wire:click="select('{{ $item->id }}')" @if ($chosen) aria-current="true" @endif
                        @class(['grid w-full gap-1.5 px-3.5 py-3 text-start focus-visible:outline-2 focus-visible:-outline-offset-2 focus-visible:outline-primary', 'bg-secondary/60 shadow-[inset_0_0_0_1px_var(--color-base-300)]' => $chosen, 'hover:bg-base-200/60' => ! $chosen])
                        data-testid="evaluation-item">
                        <span class="flex flex-wrap items-baseline gap-x-2.5 gap-y-0.5">
                            <span @class(['text-base', 'font-bold' => $chosen, 'font-semibold' => ! $chosen])>{{ $item->term }}</span>
                            <span class="text-sm text-neutral">{{ $item->scope() }}</span>
                        </span>
                        <span class="flex flex-wrap items-center gap-2">
                            @if ($item->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Done && $item->overall)
                                <x-core::status-badge :tone="$item->overall->confidence->tone()" :label="$item->overall->confidence->label()" :icon="$item->overall->confidence->icon()" />
                                <x-academic-load::percent :value="$item->overall->weightedError" class="font-semibold" />
                                <span class="text-[12.5px] text-neutral">R1 <x-academic-load::percent :value="$item->overall->baselineError" /></span>
                            @else
                                <x-core::status-badge :tone="$item->status->tone()" :label="$item->status->label()" :icon="$item->status->icon()" />
                            @endif
                        </span>
                        @if ($item->status->isPending())
                            <progress @class(['progress h-2 w-full', 'progress-info' => $item->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Running]) value="{{ $item->progress }}" max="100" aria-label="Avance de la evaluación"></progress>
                        @endif
                        @if ($item->shortError())
                            <span class="text-[12.5px] text-error">{{ $item->shortError() }}</span>
                        @endif
                        <span class="text-[12.5px] text-neutral">{{ $item->requestedAt }} · {{ $item->requestedBy }}</span>
                    </button>
                </li>
            @endforeach
        </ol>
    </div>

    <footer class="flex justify-end gap-2 border-t border-base-300 px-5 py-3 max-md:[&_.btn]:w-full">
        @include('academic-load::forecast-accuracy.evaluate-button', ['canRun' => $canRun])
    </footer>
</div>
