{{--
    El drawer «Evaluar un periodo» (DM-28): la modalidad (como dato si hay una sola, MS16), el campus si la modalidad usa
    salones (MS4; «Todos los campus» por omisión), el aviso de que lo elegido ya se evaluó y se guarda como otra (DM-29) y
    los periodos con kárdex de la modalidad (ForecastEvaluationsQuery::evaluableTerms), cada uno con cuándo se evaluó por
    última vez. Los periodos van al final porque con datos reales son muchos (desde 2014). Los errores de
    RequestForecastEvaluation salen en su campo. `$form` (forecast-accuracy.php, form()).
--}}
@php
    $modality = $form['modality'];
    $evaluated = $form['evaluated'];
    $previous = $evaluated[$newTerm] ?? null;
@endphp
<div class="flex h-dvh flex-col" data-testid="evaluate-panel">
    @include('academic-load::forecast-accuracy.drawer-header', ['id' => 'evaluate-title', 'title' => 'Evaluar un periodo', 'subtitle' => 'Nexus calcula la proyección como si corriera antes del periodo y la compara con su kárdex.'])

    <form id="evaluate-form" wire:submit="evaluate" class="flex-1 space-y-[18px] overflow-y-auto px-5 py-4">
        @if ($form['modalities']->count() > 1)
            <x-select label="Modalidad" wire:model.live="newModality" :options="$form['modalities']->map(fn ($option) => ['id' => $option->code, 'name' => $option->name])->all()" class="max-md:h-11" data-testid="new-modality" />
        @else
            <div>
                <p class="text-sm font-semibold">Modalidad</p>
                <p>{{ $modality?->name ?? 'Sin modalidades activas' }}</p>
                @error('newModality') <p class="mt-1 text-sm text-error">{{ $message }}</p> @enderror
            </div>
        @endif

        @if ($form['campuses']->count() > 1)
            <fieldset class="min-w-0">
                <legend class="mb-1.5 text-sm font-semibold">Campus</legend>
                <div role="group" aria-label="Campus" class="join grid w-full" style="grid-template-columns: repeat({{ $form['campuses']->count() + 1 }}, minmax(0, 1fr))" data-testid="new-campus">
                    @foreach ([['code' => '', 'name' => 'Todos los campus'], ...$form['campuses']->map(fn ($campus) => ['code' => $campus->code, 'name' => $campus->name])->all()] as $option)
                        <button type="button" wire:click="$set('newCampus', '{{ $option['code'] }}')" aria-pressed="{{ $newCampus === $option['code'] ? 'true' : 'false' }}"
                            @class(['btn join-item h-auto min-h-10 min-w-0 whitespace-normal px-2 py-1 leading-tight max-md:min-h-11', 'btn-primary' => $newCampus === $option['code']])>{{ $option['name'] }}</button>
                    @endforeach
                </div>
                @error('newCampus') <p class="mt-1.5 text-sm text-error">{{ $message }}</p> @enderror
            </fieldset>
        @endif

        @if ($previous)
            <x-alert icon="o-information-circle" class="alert-info alert-soft items-start" data-testid="already-evaluated">
                <div class="grid gap-0.5">
                    <p class="font-semibold">Ya la evaluaste el {{ $previous }}.</p>
                    <p>Se guarda como otra evaluación y la anterior se queda en la lista.</p>
                </div>
            </x-alert>
        @endif
        <fieldset class="min-w-0">
            <legend class="mb-1.5 text-sm font-semibold">Periodo</legend>
            @if ($form['terms']->isEmpty())
                <p class="text-sm text-neutral">{{ $modality?->name ?? 'Esta modalidad' }} no tiene periodos con kárdex.</p>
            @else
                <div class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="new-terms">
                    @foreach ($form['terms'] as $term)
                        <label wire:key="new-term-{{ $term['id'] }}" class="flex cursor-pointer items-start gap-3 px-3 py-2.5 has-[:checked]:bg-base-200 hover:bg-base-200/60">
                            <input type="radio" class="radio radio-sm mt-0.5" name="new-term" value="{{ $term['id'] }}" wire:model.live="newTerm" @if ($errors->has('newTerm')) aria-describedby="new-term-error" @endif />
                            <span class="min-w-0 grow">
                                <span class="font-semibold">{{ $term['name'] }}</span> <span class="font-mono text-[12.5px] text-neutral">{{ $term['code'] }}</span>
                                @isset($evaluated[$term['id']])
                                    <span class="block text-[12.5px] text-neutral">Evaluado el {{ $evaluated[$term['id']] }}</span>
                                @endisset
                            </span>
                        </label>
                    @endforeach
                </div>
            @endif
            @error('newTerm') <p id="new-term-error" class="mt-1.5 text-sm text-error" data-testid="new-term-error">{{ $message }}</p> @enderror
        </fieldset>
    </form>

    <footer class="flex flex-wrap items-center justify-end gap-2 border-t border-base-300 px-5 py-3">
        <span class="me-auto text-[12.5px] text-neutral max-md:w-full">Corre en segundo plano.</span>
        <x-button label="Cancelar" class="btn-ghost max-md:flex-1" @click="close()" />
        <x-button label="Evaluar" icon="o-play" type="submit" form="evaluate-form" class="btn-primary max-md:flex-1" spinner="evaluate" :disabled="$form['terms']->isEmpty()" data-testid="submit-evaluate" />
    </footer>
</div>
