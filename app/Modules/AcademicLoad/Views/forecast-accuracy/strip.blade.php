{{--
    La franja de la evaluación elegida: el periodo, la modalidad y el campus, su estado si no está lista, cuándo y quién la
    pidió y lo que tardó; a la derecha, «Evaluaciones» (el drawer con todas, con un punto si otra está corriendo) y
    «Evaluar un periodo». `$current` (ForecastEvaluationItem), `$items` y `$canRun`.
--}}
@php($otherRunning = $items->contains(fn ($item): bool => $item->id !== $current->id && $item->status->isPending()))
<section class="flex flex-wrap items-center gap-x-5 gap-y-3 rounded-box border border-base-300 bg-base-100 px-4 py-3.5 max-md:px-3.5 max-md:py-3" aria-label="Evaluación elegida" data-testid="evaluation-strip">
    <div class="grid min-w-0 flex-[1_1_22rem] gap-0.5">
        <span class="text-[11.5px] font-medium uppercase tracking-[0.09em] text-neutral">Evaluación</span>
        <div class="flex flex-wrap items-center gap-x-2.5 gap-y-1">
            <span class="text-xl font-bold leading-tight">{{ $current->term }}</span>
            <span class="text-neutral">{{ $current->scope() }}</span>
            @unless ($current->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Done)
                <x-core::status-badge :tone="$current->status->tone()" :label="$current->status->label()" :icon="$current->status->icon()" @class(['[&_svg]:animate-spin motion-reduce:[&_svg]:animate-none' => $current->status === \App\Modules\AcademicLoad\Enums\ForecastEvaluationStatus::Running]) />
            @endunless
        </div>
        <span class="text-[12.5px] text-neutral">{{ $current->requestedAt }} · {{ $current->requestedBy }}@if ($current->took) · tardó {{ $current->took }}@endif</span>
    </div>
    <div class="flex flex-wrap gap-2 max-md:grid max-md:w-full max-md:[&_.btn]:w-full">
        <x-button icon="o-list-bullet" wire:click="openEvaluations" class="btn-outline border-base-300" data-testid="open-evaluations">
            Evaluaciones <span class="font-mono text-[13px] font-semibold tabular-nums">{{ $items->count() }}</span>
            @if ($otherRunning)
                <span class="h-2 w-2 rounded-full bg-info ring-[3px] ring-info/20" aria-hidden="true"></span><span class="sr-only">, otra está corriendo</span>
            @endif
        </x-button>
        @include('academic-load::forecast-accuracy.evaluate-button', ['canRun' => $canRun])
    </div>
</section>
