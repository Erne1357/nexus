{{--
    «Buscar otro docente · nombre o RFC» (HC-21), maqueta 2-buscar-a: el campo, su ayuda y, desde tres caracteres, a lo
    más diez docentes con su RFC, su departamento (con la etiqueta «Otro departamento»), el lugar que ocupan en el
    ranking del grupo, sus horas asignadas y base y si chocan o están libres en el horario del grupo. «Elegir» avisa con
    `teacher-picked`; el choque se muestra pero no veta aquí: lo decide AssignTeacher.
--}}
<div class="grid gap-2" data-testid="teacher-search">
    <x-input label="Buscar otro docente · nombre o RFC" wire:model.live.debounce.300ms="term" icon="o-magnifying-glass" autocomplete="off" data-testid="teacher-search-input" />
    <p class="text-[12.5px] text-neutral">Mínimo 3 caracteres. Busca docentes activos de cualquier departamento.</p>

    @if ($ready)
        @if ($results === [])
            <p class="rounded-box border border-dashed border-base-300 px-3 py-4 text-center text-sm text-neutral" data-testid="teacher-search-empty">Sin resultados</p>
        @else
            <ul class="divide-y divide-base-300 overflow-hidden rounded-box border border-base-300" data-testid="teacher-search-results">
                @foreach ($results as $result)
                    <li wire:key="found-{{ $result->teacherId }}" class="grid items-center gap-x-4 gap-y-1 px-3 py-2.5 sm:grid-cols-[minmax(0,1.2fr)_minmax(0,1fr)_minmax(0,1fr)_auto]" data-testid="teacher-search-result">
                        <div class="min-w-0">
                            <p class="font-semibold">{{ $result->name }}</p>
                            <p class="font-mono text-[12.5px] text-neutral">{{ $result->rfc }}</p>
                        </div>
                        <div class="min-w-0 text-[12.5px]">
                            @if ($result->otherDepartment)
                                <span class="badge badge-sm badge-info badge-soft">Otro departamento</span>
                            @endif
                            <p>{{ implode(' · ', $result->departments) ?: 'Sin departamento' }}</p>
                            @if ($result->rankingPlace !== null)
                                <p class="text-neutral">Lugar <span class="font-mono tabular-nums">{{ $result->rankingPlace }}</span> del ranking</p>
                            @endif
                        </div>
                        <div class="flex flex-wrap items-center gap-1.5 text-[12.5px]">
                            @if ($result->baseHours !== null)
                                <span class="badge badge-sm badge-neutral badge-soft font-mono tabular-nums">{{ $result->assignedHours }} / {{ $result->baseHours }} h</span>
                            @endif
                            @if ($result->clash !== null)
                                <span class="badge badge-sm badge-error badge-soft">Choca: {{ $result->clash }}</span>
                            @else
                                <span class="badge badge-sm badge-success badge-soft">Libre en el horario</span>
                            @endif
                        </div>
                        <div class="sm:text-right">
                            <button type="button" wire:click="pick('{{ $result->teacherId }}')" @disabled(! $assignable) class="btn btn-primary btn-sm max-md:h-11" data-testid="teacher-search-pick">Elegir</button>
                        </div>
                    </li>
                @endforeach
            </ul>
        @endif
    @endif
</div>
