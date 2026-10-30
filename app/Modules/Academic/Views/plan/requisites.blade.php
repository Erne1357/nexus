{{--
    Una lista de seriación en el panel de una materia (academic::plan.subject-panel): lo que pide o quién la
    pide. Cada renglón lleva la clave en Mono, el nombre y el semestre de la otra materia, y el tipo
    (seriación o correquisito); `$requisites` son filas `requires` o `required_by` de PlanGridEditorQuery y
    `$empty`, lo que dice sin ninguna.
--}}
@if ($requisites === [])
    <p class="text-neutral">{{ $empty }}</p>
@else
    <ul class="divide-y divide-base-300 rounded-box border border-base-300">
        @foreach ($requisites as $requisite)
            <li class="flex items-center gap-3 px-3 py-2" wire:key="requisite-{{ $requisite['prerequisite_id'] }}-{{ $requisite['subject_id'] }}">
                <span class="w-[5.5rem] shrink-0 font-mono text-[13.5px] font-semibold">{{ $requisite['code'] }}</span>
                <span class="min-w-0 flex-1 leading-snug">
                    {{ $requisite['name'] }}
                    <span class="block text-xs text-neutral">Semestre <span class="font-mono">{{ $requisite['semester'] }}</span></span>
                </span>
                <span class="shrink-0 text-sm text-neutral">{{ $requisite['kind'] === 'seriacion' ? 'Seriación' : 'Correquisito' }}</span>
            </li>
        @endforeach
    </ul>
@endif
